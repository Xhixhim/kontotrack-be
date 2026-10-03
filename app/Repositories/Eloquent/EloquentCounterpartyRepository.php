<?php

namespace App\Repositories\Eloquent;

use App\Models\Business;
use App\Models\Counterparty;
use App\Models\InventoryItem;
use App\Models\Invoice;
use App\Repositories\Contracts\CounterpartyRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class EloquentCounterpartyRepository implements CounterpartyRepository
{
    /** @return Collection<int, Counterparty> */
    public function listForBusiness(Business $business, ?string $type = null): Collection
    {
        return $business->counterparties()
            ->when($type !== null, fn ($query) => $query->whereIn('type', [$type, 'both']))
            ->orderBy('name')
            ->get();
    }

    public function findForBusiness(Business $business, int $counterpartyId): Counterparty
    {
        return $business->counterparties()->whereKey($counterpartyId)->lockForUpdate()->firstOrFail();
    }

    public function findForStatement(Business $business, int $counterpartyId): Counterparty
    {
        return $business->counterparties()->whereKey($counterpartyId)->firstOrFail();
    }

    /** @return list<array{date: string, type: string, invoice_id: int, invoice_number: string, payment_id: int|null, reference: string|null, increase_cents: int, decrease_cents: int}> */
    public function statementEvents(Counterparty $counterparty, string $kind, string $to): array
    {
        $invoices = Invoice::query()
            ->where('business_id', $counterparty->business_id)
            ->where('counterparty_id', $counterparty->getKey())
            ->where('kind', $kind)
            ->whereIn('status', ['issued', 'partially_paid', 'paid', 'cancelled'])
            ->whereDate('issued_on', '<=', $to)
            ->get(['id', 'invoice_number', 'issued_on', 'gross_amount', 'status', 'cancelled_on', 'document_type']);
        $events = [];

        foreach ($invoices as $invoice) {
            $grossCents = $this->toCents((string) $invoice->gross_amount);
            $isCreditNote = $invoice->document_type === 'credit_note';
            $events[] = [
                'date' => $invoice->issued_on->toDateString(),
                'type' => $isCreditNote ? 'credit_note' : 'invoice',
                'invoice_id' => (int) $invoice->id,
                'invoice_number' => (string) $invoice->invoice_number,
                'payment_id' => null,
                'reference' => null,
                'increase_cents' => $isCreditNote ? 0 : $grossCents,
                'decrease_cents' => $isCreditNote ? $grossCents : 0,
            ];

            if ($invoice->status === 'cancelled' && $invoice->cancelled_on !== null && $invoice->cancelled_on->toDateString() <= $to) {
                $events[] = [
                    'date' => $invoice->cancelled_on->toDateString(),
                    'type' => 'cancellation',
                    'invoice_id' => (int) $invoice->id,
                    'invoice_number' => (string) $invoice->invoice_number,
                    'payment_id' => null,
                    'reference' => null,
                    'increase_cents' => 0,
                    'decrease_cents' => $grossCents,
                ];
            }
        }

        $allocations = DB::table('invoice_payment as allocations')
            ->join('payments', function ($join): void {
                $join->on('payments.id', '=', 'allocations.payment_id')
                    ->on('payments.business_id', '=', 'allocations.business_id');
            })
            ->join('invoices', function ($join): void {
                $join->on('invoices.id', '=', 'allocations.invoice_id')
                    ->on('invoices.business_id', '=', 'allocations.business_id');
            })
            ->where('allocations.business_id', $counterparty->business_id)
            ->where('invoices.counterparty_id', $counterparty->getKey())
            ->where('invoices.kind', $kind)
            ->whereDate('payments.paid_on', '<=', $to)
            ->get(['allocations.amount', 'payments.id as payment_id', 'payments.paid_on', 'payments.reference', 'invoices.id as invoice_id', 'invoices.invoice_number']);

        foreach ($allocations as $allocation) {
            $events[] = [
                'date' => substr((string) $allocation->paid_on, 0, 10),
                'type' => 'payment',
                'invoice_id' => (int) $allocation->invoice_id,
                'invoice_number' => (string) $allocation->invoice_number,
                'payment_id' => (int) $allocation->payment_id,
                'reference' => $allocation->reference === null ? null : (string) $allocation->reference,
                'increase_cents' => 0,
                'decrease_cents' => $this->toCents((string) $allocation->amount),
            ];
        }

        $typeOrder = ['invoice' => 0, 'credit_note' => 1, 'payment' => 2, 'cancellation' => 3];
        usort($events, fn (array $left, array $right): int => [$left['date'], $typeOrder[$left['type']], $left['invoice_id']] <=> [$right['date'], $typeOrder[$right['type']], $right['invoice_id']]);

        return $events;
    }

    /** @return list<array{id: int, invoice_number: string, issued_on: string, due_on: string|null, status: string, gross_cents: int, paid_cents: int}> */
    public function openInvoices(Counterparty $counterparty, string $kind): array
    {
        return Invoice::query()
            ->where('business_id', $counterparty->business_id)
            ->where('counterparty_id', $counterparty->getKey())
            ->where('kind', $kind)
            ->where('document_type', 'invoice')
            ->whereIn('status', ['issued', 'partially_paid'])
            ->with('payments')
            ->orderByRaw('due_on IS NULL')
            ->orderBy('due_on')
            ->orderBy('issued_on')
            ->get()
            ->map(fn (Invoice $invoice): array => [
                'id' => (int) $invoice->id,
                'invoice_number' => (string) $invoice->invoice_number,
                'issued_on' => $invoice->issued_on->toDateString(),
                'due_on' => $invoice->due_on?->toDateString(),
                'status' => (string) $invoice->status,
                'gross_cents' => $this->toCents((string) $invoice->gross_amount),
                'paid_cents' => (int) $invoice->payments->sum(fn ($payment): int => $this->toCents((string) $payment->pivot->amount)) + $this->toCents((string) $invoice->credited_total),
            ])
            ->all();
    }

    public function hasInvoicesOfKind(Counterparty $counterparty, string $kind): bool
    {
        return Invoice::query()
            ->where('business_id', $counterparty->business_id)
            ->where('counterparty_id', $counterparty->getKey())
            ->where('kind', $kind)
            ->exists();
    }

    public function suppliesInventoryItems(Counterparty $counterparty): bool
    {
        return InventoryItem::query()
            ->where('business_id', $counterparty->business_id)
            ->where('supplier_counterparty_id', $counterparty->getKey())
            ->exists();
    }

    public function save(Counterparty $counterparty): Counterparty
    {
        $counterparty->save();

        return $counterparty;
    }

    /** @param array<string, mixed> $attributes */
    public function createForBusiness(Business $business, array $attributes): Counterparty
    {
        return $business->counterparties()->create($attributes);
    }

    private function toCents(string $amount): int
    {
        $negative = str_starts_with($amount, '-');
        $amount = ltrim($amount, '+-');
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        $cents = ((int) $whole * 100) + (int) substr(str_pad($fraction, 2, '0'), 0, 2);

        return $negative ? -$cents : $cents;
    }
}
