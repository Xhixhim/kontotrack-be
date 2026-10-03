<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Services\Reports\FinancialReportService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FinancialReportController extends Controller
{
    public function __construct(private FinancialReportService $financialReportService) {}

    public function index(Request $request, Business $business): JsonResponse
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

        return response()->json([
            'data' => $this->financialReportService->generate($business, $filters['type'], $from, $to, [
                'account_id' => isset($filters['account_id']) ? (int) $filters['account_id'] : null,
                'cash_account_id' => isset($filters['cash_account_id']) ? (int) $filters['cash_account_id'] : null,
            ]),
        ]);
    }
}
