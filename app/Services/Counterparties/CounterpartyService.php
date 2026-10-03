<?php

namespace App\Services\Counterparties;

use App\Models\Business;
use App\Models\Counterparty;
use App\Models\User;
use App\Repositories\Contracts\CounterpartyRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CounterpartyService
{
    public function __construct(private CounterpartyRepository $counterpartyRepository) {}

    /** @return Collection<int, Counterparty> */
    public function listForBusiness(Business $business, ?string $type = null): Collection
    {
        return $this->counterpartyRepository->listForBusiness($business, $type);
    }

    /**
     * Build the contact card: balances, open invoices, and a running statement for each side the contact trades on.
     *
     * @return array<string, mixed>
     */
    public function statement(Business $business, int $counterpartyId, string $from, string $to, string $today): array
    {
        $counterparty = $this->counterpartyRepository->findForStatement($business, $counterpartyId);
        $sides = match ($counterparty->type) {
            'customer' => ['sale'],
            'supplier' => ['purchase'],
            default => ['sale', 'purchase'],
        };
        $sections = [];

        foreach ($sides as $kind) {
            $events = $this->counterpartyRepository->statementEvents($counterparty, $kind, $to);
            $openingCents = 0;
            $runningCents = 0;
            $lines = [];

            foreach ($events as $event) {
                $runningCents += $event['increase_cents'] - $event['decrease_cents'];

                if ($event['date'] < $from) {
                    $openingCents = $runningCents;

                    continue;
                }

                $lines[] = [
                    'date' => $event['date'],
                    'type' => $event['type'],
                    'invoice_id' => $event['invoice_id'],
                    'invoice_number' => $event['invoice_number'],
                    'payment_id' => $event['payment_id'],
                    'reference' => $event['reference'],
                    'increase' => $this->fromCents($event['increase_cents']),
                    'decrease' => $this->fromCents($event['decrease_cents']),
                    'balance' => $this->fromCents($runningCents),
                ];
            }

            $rawOpenInvoices = $this->counterpartyRepository->openInvoices($counterparty, $kind);
            $openInvoices = array_map(fn (array $invoice): array => [
                'id' => $invoice['id'],
                'invoice_number' => $invoice['invoice_number'],
                'issued_on' => $invoice['issued_on'],
                'due_on' => $invoice['due_on'],
                'status' => $invoice['status'],
                'gross_amount' => $this->fromCents($invoice['gross_cents']),
                'outstanding_amount' => $this->fromCents($invoice['gross_cents'] - $invoice['paid_cents']),
                'days_overdue' => $invoice['due_on'] !== null && $invoice['due_on'] < $today
                    ? (int) Carbon::parse($invoice['due_on'])->diffInDays(Carbon::parse($today))
                    : 0,
            ], $rawOpenInvoices);
            $outstandingCents = array_sum(array_map(fn (array $invoice): int => $invoice['gross_cents'] - $invoice['paid_cents'], $rawOpenInvoices));
            $overdueInvoices = array_filter($openInvoices, fn (array $invoice): bool => $invoice['days_overdue'] > 0);

            $sections[] = [
                'kind' => $kind,
                'opening_balance' => $this->fromCents($openingCents),
                'closing_balance' => $this->fromCents($runningCents),
                'lines' => $lines,
                'open_invoices' => $openInvoices,
                'outstanding_amount' => $this->fromCents($outstandingCents),
                'overdue_amount' => $this->fromCents(array_sum(array_map(fn (array $invoice): int => $this->toCents($invoice['outstanding_amount']), $overdueInvoices))),
                'oldest_days_overdue' => $overdueInvoices === [] ? 0 : max(array_column($overdueInvoices, 'days_overdue')),
            ];
        }

        return [
            'counterparty' => $counterparty,
            'from' => $from,
            'to' => $to,
            'currency' => $business->currency,
            'sections' => $sections,
        ];
    }

    /** @param array<string, mixed> $attributes */
    public function update(Business $business, User $user, int $counterpartyId, array $attributes): Counterparty
    {
        return DB::transaction(function () use ($business, $user, $counterpartyId, $attributes): Counterparty {
            $counterparty = $this->counterpartyRepository->findForBusiness($business, $counterpartyId);
            $counterparty->fill($attributes);

            if ($counterparty->isDirty('type')) {
                if ($counterparty->type === 'supplier' && $this->counterpartyRepository->hasInvoicesOfKind($counterparty, 'sale')) {
                    throw ValidationException::withMessages(['type' => ['This contact has sales invoices, so it must stay a customer or both.']]);
                }

                if ($counterparty->type === 'customer' && $this->counterpartyRepository->hasInvoicesOfKind($counterparty, 'purchase')) {
                    throw ValidationException::withMessages(['type' => ['This contact has purchase invoices, so it must stay a supplier or both.']]);
                }

                if ($counterparty->type === 'customer' && $this->counterpartyRepository->suppliesInventoryItems($counterparty)) {
                    throw ValidationException::withMessages(['type' => ['This contact is the supplier of inventory items, so it must stay a supplier or both.']]);
                }
            }

            $newValues = $counterparty->getDirty();

            if ($newValues === []) {
                return $counterparty;
            }

            $oldValues = array_map(fn (string $attribute): mixed => $counterparty->getRawOriginal($attribute), array_combine(array_keys($newValues), array_keys($newValues)));
            $this->counterpartyRepository->save($counterparty);
            $business->auditLogs()->create([
                'user_id' => $user->getKey(),
                'action' => 'counterparty.updated',
                'subject_type' => Counterparty::class,
                'subject_id' => $counterparty->getKey(),
                'old_values' => $oldValues,
                'new_values' => $newValues,
            ]);

            return $counterparty;
        });
    }

    /** @param array<string, mixed> $attributes */
    public function createForBusiness(Business $business, int $userId, array $attributes): Counterparty
    {
        return DB::transaction(function () use ($business, $userId, $attributes): Counterparty {
            $counterparty = $this->counterpartyRepository->createForBusiness($business, $attributes);
            $business->auditLogs()->create([
                'user_id' => $userId,
                'action' => 'counterparty.created',
                'subject_type' => Counterparty::class,
                'subject_id' => $counterparty->getKey(),
                'new_values' => $counterparty->only(['type', 'name', 'nui']),
            ]);

            return $counterparty;
        });
    }

    private function toCents(string $amount): int
    {
        $negative = str_starts_with($amount, '-');
        $amount = ltrim($amount, '+-');
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        $cents = ((int) $whole * 100) + (int) substr(str_pad($fraction, 2, '0'), 0, 2);

        return $negative ? -$cents : $cents;
    }

    private function fromCents(int $amount): string
    {
        $sign = $amount < 0 ? '-' : '';
        $absoluteAmount = abs($amount);

        return $sign.intdiv($absoluteAmount, 100).'.'.str_pad((string) ($absoluteAmount % 100), 2, '0', STR_PAD_LEFT);
    }
}
