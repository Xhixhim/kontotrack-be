<?php

namespace App\Repositories\Contracts;

use App\Models\Business;
use Illuminate\Support\Collection;

interface FinancialReportRepository
{
    /**
     * @return array{
     *     type: string,
     *     from: string,
     *     to: string,
     *     rows: list<array{code: string, name: string, account_type: string, debit: string, credit: string}>,
     *     totals: array{debit: string, credit: string, result: string}
     * }
     */
    public function generate(Business $business, string $type, string $from, string $to, ?int $unitId = null): array;

    /** @return array<string, mixed> */
    public function generateInvoiceAging(Business $business, string $kind, string $asOf): array;

    /**
     * Account cards: opening balance, posted lines with a running balance, and closing balance per account.
     *
     * @param  list<int>|null  $accountIds  null for every account with activity
     * @return list<array<string, mixed>>
     */
    public function generalLedger(Business $business, ?array $accountIds, string $from, string $to, ?int $unitId = null): array;

    /** @return array<string, mixed> */
    public function generateVatSummary(Business $business, string $from, string $to): array;

    /** @return Collection<int, object> */
    public function vatLines(Business $business, string $from, string $to): Collection;
}
