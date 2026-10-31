<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\PayrollRun;
use App\Services\Payroll\PayrollService;
use App\Services\Tax\AtkExportService;
use App\Services\Tax\AtkVatBookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AtkExportController extends Controller
{
    public function __construct(
        private AtkVatBookService $vatBookService,
        private AtkExportService $exportService,
        private PayrollService $payrollService,
    ) {}

    public function vatBooks(Request $request, Business $business): JsonResponse
    {
        return response()->json(['data' => $this->vatBookService->books($business, $this->period($request))]);
    }

    public function vatBookFile(Request $request, Business $business, string $book): Response
    {
        $period = $this->period($request);
        $books = $this->vatBookService->books($business, $period);
        $isSales = $book === 'sales';
        $business->auditLogs()->create([
            'user_id' => $request->user()->getKey(),
            'action' => 'atk.vat_book_exported',
            'subject_type' => Business::class,
            'subject_id' => $business->getKey(),
            'new_values' => ['book' => $book, 'period' => $period, 'rows' => count($books[$isSales ? 'sales' : 'purchases']['rows'])],
        ]);

        return $this->xlsx(
            $isSales ? $this->exportService->salesBook($books['sales']) : $this->exportService->purchaseBook($books['purchases']),
            ($isSales ? 'Libri-i-Shitjes' : 'Libri-i-Blerjes')."-{$business->nui}-{$period}.xlsx",
        );
    }

    public function payrollList(Request $request, Business $business, int $payrollRun): JsonResponse|Response
    {
        $run = $this->payrollService->findRun($business, $payrollRun);
        $export = $this->exportService->payrollList($business, $run);

        if (! $request->boolean('download')) {
            return response()->json(['data' => ['period' => $this->runPeriod($run), 'employees' => $run->lines()->count(), 'problems' => $export['problems']]]);
        }

        $business->auditLogs()->create([
            'user_id' => $request->user()->getKey(),
            'action' => 'atk.payroll_list_exported',
            'subject_type' => PayrollRun::class,
            'subject_id' => $run->getKey(),
            'new_values' => ['period' => $this->runPeriod($run)],
        ]);

        return $this->xlsx($export['contents'], "Lista-e-pagave-WM-{$business->nui}-{$this->runPeriod($run)}.xlsx");
    }

    private function period(Request $request): string
    {
        return $request->validate(['period' => ['required', 'date_format:Y-m']])['period'];
    }

    private function runPeriod(PayrollRun $run): string
    {
        return sprintf('%04d-%02d', $run->period_year, $run->period_month);
    }

    private function xlsx(string $contents, string $filename): Response
    {
        return response($contents, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}
