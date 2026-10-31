<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Services\Reports\DocumentExportService;
use App\Services\Reports\FinancialReportService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class FinancialReportController extends Controller
{
    public function __construct(private FinancialReportService $financialReportService, private DocumentExportService $exportService) {}

    public function index(Request $request, Business $business): JsonResponse
    {
        [$type, $from, $to, $options] = $this->filters($request);

        return response()->json(['data' => $this->financialReportService->generate($business, $type, $from, $to, $options)]);
    }

    public function export(Request $request, Business $business): Response
    {
        $format = $request->validate(['format' => ['required', 'string', 'in:xlsx,pdf']])['format'];
        [$type, $from, $to, $options] = $this->filters($request);
        $file = $this->exportService->report($business, $type, $this->financialReportService->generate($business, $type, $from, $to, $options), $format);

        return response($file['contents'], 200, [
            'Content-Type' => $file['mime'],
            'Content-Disposition' => 'attachment; filename="'.$file['filename'].'"',
        ]);
    }

    /** @return array{string, string, string, array{account_id: int|null, cash_account_id: int|null}} */
    private function filters(Request $request): array
    {
        $filters = $request->validate([
            'type' => ['required', 'string', 'in:trial_balance,profit_loss,balance_sheet,receivable_aging,payable_aging,vat_summary,general_ledger,cash_book'],
            'account_id' => ['nullable', 'integer'],
            'cash_account_id' => ['nullable', 'integer', 'required_if:type,cash_book'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
        ]);
        $to = CarbonImmutable::parse($filters['to'] ?? today())->toDateString();
        $from = CarbonImmutable::parse($filters['from'] ?? CarbonImmutable::parse($to)->startOfMonth())->toDateString();
        $isAgingReport = in_array($filters['type'], ['receivable_aging', 'payable_aging'], true);

        if (! $isAgingReport && $from > $to) {
            throw ValidationException::withMessages(['from' => ['The start date must not be after the end date.']]);
        }

        return [$filters['type'], $from, $to, [
            'account_id' => isset($filters['account_id']) ? (int) $filters['account_id'] : null,
            'cash_account_id' => isset($filters['cash_account_id']) ? (int) $filters['cash_account_id'] : null,
        ]];
    }
}
