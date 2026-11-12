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
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class FinancialReportController extends Controller
{
    public function __construct(private FinancialReportService $financialReportService, private DocumentExportService $exportService) {}

    public function index(Request $request, Business $business): JsonResponse
    {
        [$type, $from, $to, $options] = $this->filters($request, $business);

        return response()->json(['data' => $this->financialReportService->generate($business, $type, $from, $to, $options)]);
    }

    public function export(Request $request, Business $business): Response
    {
        $format = $request->validate(['format' => ['required', 'string', 'in:xlsx,pdf']])['format'];
        [$type, $from, $to, $options] = $this->filters($request, $business);
        $file = $this->exportService->report($business, $type, $this->financialReportService->generate($business, $type, $from, $to, $options), $format);

        return response($file['contents'], 200, [
            'Content-Type' => $file['mime'],
            'Content-Disposition' => 'attachment; filename="'.$file['filename'].'"',
        ]);
    }

    /** @return array{string, string, string, array<string, int|string|null>} */
    private function filters(Request $request, Business $business): array
    {
        $filters = $request->validate([
            'type' => ['required', 'string', 'in:trial_balance,profit_loss,balance_sheet,receivable_aging,payable_aging,vat_summary,general_ledger,cash_book,bank_reconciliation,turnover,stock,payroll,fixed_assets'],
            'account_id' => ['nullable', 'integer'],
            'cash_account_id' => ['nullable', 'integer', 'required_if:type,cash_book'],
            'bank_account_id' => ['nullable', 'integer', 'required_if:type,bank_reconciliation'],
            // The closing balance printed on the bank statement, when the imported file had no balance column.
            'statement_balance' => ['nullable', 'numeric', 'decimal:0,2', 'min:-99999999999999.99', 'max:99999999999999.99'],
            'counterparty_id' => ['nullable', 'integer'],
            'warehouse_id' => ['nullable', 'integer'],
            'category' => ['nullable', 'string', 'max:120'],
            'group_by' => ['nullable', 'string', 'in:month,counterparty'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
        ]);

        // Salaries are visible only to members who may see payroll.
        if ($filters['type'] === 'payroll') {
            Gate::authorize('viewPayroll', $business);
        }

        $to = CarbonImmutable::parse($filters['to'] ?? today())->toDateString();
        $from = CarbonImmutable::parse($filters['from'] ?? CarbonImmutable::parse($to)->startOfMonth())->toDateString();
        $isAgingReport = in_array($filters['type'], ['receivable_aging', 'payable_aging', 'bank_reconciliation', 'fixed_assets'], true);

        if (! $isAgingReport && $from > $to) {
            throw ValidationException::withMessages(['from' => ['The start date must not be after the end date.']]);
        }

        return [$filters['type'], $from, $to, [
            'account_id' => isset($filters['account_id']) ? (int) $filters['account_id'] : null,
            'cash_account_id' => isset($filters['cash_account_id']) ? (int) $filters['cash_account_id'] : null,
            'bank_account_id' => isset($filters['bank_account_id']) ? (int) $filters['bank_account_id'] : null,
            'statement_balance' => isset($filters['statement_balance']) ? (string) $filters['statement_balance'] : null,
            'counterparty_id' => isset($filters['counterparty_id']) ? (int) $filters['counterparty_id'] : null,
            'warehouse_id' => isset($filters['warehouse_id']) ? (int) $filters['warehouse_id'] : null,
            'category' => $filters['category'] ?? null,
            'group_by' => $filters['group_by'] ?? null,
        ]];
    }
}
