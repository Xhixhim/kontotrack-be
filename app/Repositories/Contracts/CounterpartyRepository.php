<?php

namespace App\Repositories\Contracts;

use App\Models\Business;
use App\Models\Counterparty;
use Illuminate\Database\Eloquent\Collection;

interface CounterpartyRepository
{
    /** @return Collection<int, Counterparty> */
    public function listForBusiness(Business $business, ?string $type = null): Collection;

    public function findForBusiness(Business $business, int $counterpartyId): Counterparty;

    public function findForStatement(Business $business, int $counterpartyId): Counterparty;

    /**
     * Every invoice, cancellation, and payment allocation for one side of the contact, up to the given date.
     *
     * @return list<array{date: string, type: string, invoice_id: int, invoice_number: string, payment_id: int|null, reference: string|null, increase_cents: int, decrease_cents: int}>
     */
    public function statementEvents(Counterparty $counterparty, string $kind, string $to): array;

    /** @return list<array{id: int, invoice_number: string, issued_on: string, due_on: string|null, status: string, gross_cents: int, paid_cents: int}> */
    public function openInvoices(Counterparty $counterparty, string $kind): array;

    public function hasInvoicesOfKind(Counterparty $counterparty, string $kind): bool;

    public function suppliesInventoryItems(Counterparty $counterparty): bool;

    public function save(Counterparty $counterparty): Counterparty;

    /** @param array<string, mixed> $attributes */
    public function createForBusiness(Business $business, array $attributes): Counterparty;
}
