<?php

namespace App\Repositories\Contracts;

use App\Models\AccountingPeriod;
use App\Models\Business;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

interface AccountingPeriodRepository
{
    /** @return Collection<int, AccountingPeriod> */
    public function listForBusiness(Business $business): Collection;

    public function overlaps(Business $business, string $startsOn, string $endsOn): bool;

    /** @param array<string, mixed> $attributes */
    public function createForBusiness(Business $business, array $attributes): AccountingPeriod;

    public function findForUpdate(Business $business, int $periodId): AccountingPeriod;

    public function findForClosingReview(Business $business, int $periodId): AccountingPeriod;

    /**
     * @return array{
     *     draft_invoice_count: int,
     *     purchase_invoice_missing_document_count: int,
     *     payment_missing_document_count: int,
     *     bank_transaction_missing_document_count: int,
     *     missing_journal_source_count: int,
     *     unreconciled_bank_transaction_count: int,
     *     negative_cash_account_count: int,
     *     has_employees: bool,
     *     months_without_payroll: list<string>,
     *     tracks_stock: bool,
     *     negative_stock: list<string>
     * }
     */
    public function closingData(Business $business, AccountingPeriod $period): array;

    public function markClosed(AccountingPeriod $period, User $user): AccountingPeriod;
}
