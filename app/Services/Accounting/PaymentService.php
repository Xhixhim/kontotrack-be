<?php

namespace App\Services\Accounting;

use App\Models\BankAccount;
use App\Models\Business;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\User;
use App\Repositories\Contracts\InvoiceRepository;
use App\Repositories\Contracts\JournalEntryRepository;
use App\Repositories\Contracts\PaymentRepository;
use App\Services\Documents\DocumentService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    public function __construct(
        private PaymentRepository $paymentRepository,
        private JournalEntryRepository $journalEntryRepository,
        private InvoiceRepository $invoiceRepository,
        private DocumentService $documentService,
        private JournalEntryService $journalEntryService,
        private PostingAccountService $postingAccountService,
    ) {}

    /** @return LengthAwarePaginator<int, Payment> */
    public function listForBusiness(Business $business): LengthAwarePaginator
    {
        return $this->paymentRepository->listForBusiness($business);
    }

    /** @param array<string, mixed> $attributes */
    public function createForBusiness(Business $business, User $user, array $attributes): Payment
    {
        return DB::transaction(function () use ($business, $user, $attributes): Payment {
            $document = $this->documentService->findForBusiness($business, (int) $attributes['document_id']);
            $isCash = isset($attributes['cash_account_id']);
            $isBank = isset($attributes['bank_account_id']);

            if ($isCash === $isBank) {
                throw ValidationException::withMessages(['cash_account_id' => ['Choose exactly one cash or bank account.']]);
            }

            $accountId = null;
            $cashAccountId = null;
            $bankAccountId = null;
            $bankTransactionId = null;

            if ($isCash) {
                $cashAccount = $business->cashAccounts()
                    ->whereKey($attributes['cash_account_id'])
                    ->where('is_active', true)
                    ->with('account')
                    ->first();

                if ($cashAccount === null || $cashAccount->currency !== $business->currency || $cashAccount->account === null || ! $cashAccount->account->is_active || $cashAccount->account->account_type !== 'asset') {
                    throw ValidationException::withMessages(['cash_account_id' => ['Choose an active cash account mapped to an active asset account in this business.']]);
                }

                $accountId = (int) $cashAccount->account_id;
                $cashAccountId = (int) $cashAccount->getKey();
            } else {
                $bankAccount = $business->bankAccounts()
                    ->whereKey($attributes['bank_account_id'])
                    ->where('is_active', true)
                    ->with('account')
                    ->first();

                if ($bankAccount === null || $bankAccount->currency !== $business->currency || $bankAccount->account === null || ! $bankAccount->account->is_active || $bankAccount->account->account_type !== 'asset') {
                    throw ValidationException::withMessages(['bank_account_id' => ['Choose an active bank account mapped to an active asset account in this business.']]);
                }

                $accountId = (int) $bankAccount->account_id;
                $bankAccountId = (int) $bankAccount->getKey();
            }

            $amountCents = $this->toCents($attributes['amount']);

            if (isset($attributes['allocations'])) {
                $this->validateAllocations($business, $attributes['allocations'], $amountCents, $attributes['direction'], $attributes['paid_on']);
            }

            $journalEntryId = isset($attributes['journal_entry_id'])
                ? (int) $attributes['journal_entry_id']
                : $this->generateJournalEntry($business, $user, $attributes, $accountId, $amountCents, (int) $document->getKey());
            $entry = $this->journalEntryRepository->findForPosting($business, $journalEntryId);

            if ($entry->status !== 'posted' || $entry->entry_date->toDateString() !== $attributes['paid_on']) {
                throw ValidationException::withMessages(['journal_entry_id' => ['Choose a posted journal entry dated on the payment date.']]);
            }

            if ($this->paymentRepository->hasPaymentForJournalEntry($business, (int) $entry->getKey())) {
                throw ValidationException::withMessages(['journal_entry_id' => ['This journal entry already has a recorded payment.']]);
            }

            $assetNetCents = 0;

            foreach ($entry->lines->where('account_id', $accountId) as $line) {
                $assetNetCents += $this->toCents($line->debit) - $this->toCents($line->credit);
            }

            $expectedAssetNet = $attributes['direction'] === 'in' ? $amountCents : -$amountCents;

            if ($assetNetCents !== $expectedAssetNet) {
                throw ValidationException::withMessages(['amount' => ['The payment amount and direction must match the mapped cash or bank account movement in the posted journal entry.']]);
            }

            if (isset($bankAccount)) {
                $signedAmount = $attributes['direction'] === 'in' ? $amountCents : -$amountCents;
                $bankTransactionId = isset($attributes['bank_transaction_id'])
                    ? $this->reconcileStatementLine($bankAccount, (int) $attributes['bank_transaction_id'], $entry, $signedAmount, $attributes['paid_on'])
                    : (int) $bankAccount->transactions()->create([
                        'business_id' => $business->getKey(),
                        'source' => 'payment',
                        'journal_entry_id' => $entry->getKey(),
                        'transaction_date' => $attributes['paid_on'],
                        'description' => $attributes['reference'] ?? $entry->description,
                        'amount' => $this->fromCents($signedAmount),
                        'currency' => $business->currency,
                        'document_id' => $document->getKey(),
                        'is_reconciled' => false,
                    ])->getKey();
            }

            $payment = $this->paymentRepository->createForBusiness($business, [
                'cash_account_id' => $cashAccountId,
                'bank_account_id' => $bankAccountId,
                'bank_transaction_id' => $bankTransactionId,
                'document_id' => $document->getKey(),
                'journal_entry_id' => $entry->getKey(),
                'paid_on' => $attributes['paid_on'],
                'direction' => $attributes['direction'],
                'reference' => $attributes['reference'] ?? null,
                'amount' => $this->fromCents($amountCents),
                'currency' => $business->currency,
                'notes' => $attributes['notes'] ?? null,
                'created_by' => $user->getKey(),
            ]);

            $normalisedAllocations = [];

            if (! empty($attributes['allocations'])) {
                $normalisedAllocations = array_map(fn (array $allocation): array => [
                    'invoice_id' => (int) $allocation['invoice_id'],
                    'amount' => $this->fromCents($this->toCents($allocation['amount'])),
                ], $attributes['allocations']);

                $this->paymentRepository->allocateToInvoices($payment, $business, $normalisedAllocations);

                foreach ($normalisedAllocations as $allocation) {
                    $invoice = $this->invoiceRepository->findForPayment($business, $allocation['invoice_id']);
                    $allocatedCents = $invoice->payments->sum(fn (Payment $invoicePayment): int => $this->toCents($invoicePayment->pivot->amount));
                    $newStatus = $allocatedCents + $this->toCents((string) $invoice->credited_total) >= $this->toCents($invoice->gross_amount) ? 'paid' : 'partially_paid';
                    $oldStatus = $invoice->status;

                    if ($oldStatus !== $newStatus) {
                        $this->invoiceRepository->updatePaymentStatus($invoice, $newStatus);
                        $business->auditLogs()->create([
                            'user_id' => $user->getKey(),
                            'action' => 'invoice.payment_status_changed',
                            'subject_type' => Invoice::class,
                            'subject_id' => $invoice->getKey(),
                            'old_values' => ['status' => $oldStatus],
                            'new_values' => ['status' => $newStatus, 'payment_id' => $payment->getKey()],
                        ]);
                    }
                }
            }

            $business->auditLogs()->create([
                'user_id' => $user->getKey(),
                'action' => 'payment.recorded',
                'subject_type' => Payment::class,
                'subject_id' => $payment->getKey(),
                'new_values' => [
                    'journal_entry_id' => $entry->getKey(),
                    'direction' => $payment->direction,
                    'amount' => $payment->amount,
                    'paid_on' => $payment->paid_on->toDateString(),
                    'document_id' => $document->getKey(),
                    'allocations' => $normalisedAllocations,
                ],
            ]);

            return $payment->load(['cashAccount.account', 'bankAccount.account', 'journalEntry', 'invoices']);
        });
    }

    /**
     * Create and post the journal entry for a payment that is fully allocated to invoices.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function generateJournalEntry(Business $business, User $user, array $attributes, int $assetAccountId, int $amountCents, int $documentId): int
    {
        $allocations = $attributes['allocations'] ?? [];
        $allocatedCents = array_sum(array_map(fn (array $allocation): int => $this->toCents($allocation['amount']), $allocations));
        $offsetAccountId = isset($attributes['offset_account_id']) ? (int) $attributes['offset_account_id'] : null;

        if ($offsetAccountId === null && ($allocations === [] || $allocatedCents !== $amountCents)) {
            throw ValidationException::withMessages(['allocations' => ['To post automatically, allocate the full payment amount to invoices, choose the account the money is for, or choose a posted journal entry.']]);
        }

        $periodId = $this->journalEntryService->findOpenPeriodIdForDate($business, $attributes['paid_on']);

        if ($periodId === null) {
            throw ValidationException::withMessages(['paid_on' => ['Open an accounting period that contains the payment date before recording it.']]);
        }

        if ($offsetAccountId !== null) {
            return $this->postAgainstOffsetAccount($business, $user, $attributes, $assetAccountId, $offsetAccountId, $amountCents, $documentId, $periodId);
        }

        $isReceipt = $attributes['direction'] === 'in';
        $purpose = $isReceipt ? 'accounts_receivable' : 'accounts_payable';
        $controlAccountId = (int) $this->postingAccountService->requireAccounts($business, [$purpose])[$purpose]->getKey();
        $centsByCounterparty = [];

        foreach ($allocations as $allocation) {
            $counterpartyId = (int) $this->invoiceRepository->findForPayment($business, (int) $allocation['invoice_id'])->counterparty_id;
            $centsByCounterparty[$counterpartyId] = ($centsByCounterparty[$counterpartyId] ?? 0) + $this->toCents($allocation['amount']);
        }

        $counterpartyNames = $business->counterparties()->whereIn('id', array_keys($centsByCounterparty))->pluck('name', 'id');
        $assetLine = [
            'account_id' => $assetAccountId,
            'counterparty_id' => null,
            'description' => $isReceipt ? 'Money received' : 'Money paid',
            'debit' => $isReceipt ? $this->fromCents($amountCents) : '0.00',
            'credit' => $isReceipt ? '0.00' : $this->fromCents($amountCents),
        ];
        $controlLines = [];

        foreach ($centsByCounterparty as $counterpartyId => $cents) {
            $controlLines[] = [
                'account_id' => $controlAccountId,
                'counterparty_id' => $counterpartyId,
                'description' => mb_substr(($isReceipt ? 'Receipt from ' : 'Payment to ').$counterpartyNames->get($counterpartyId), 0, 255),
                'debit' => $isReceipt ? '0.00' : $this->fromCents($cents),
                'credit' => $isReceipt ? $this->fromCents($cents) : '0.00',
            ];
        }

        $reference = $attributes['reference'] ?? null;
        $journalEntry = $this->journalEntryService->createDraft($business, $user, [
            'accounting_period_id' => $periodId,
            'entry_date' => $attributes['paid_on'],
            'reference' => $reference === null ? null : mb_substr($reference, 0, 100),
            'description' => mb_substr(($isReceipt ? 'Customer receipt' : 'Supplier payment').($reference === null ? '' : ' '.$reference), 0, 255),
            'document_id' => $documentId,
            'lines' => $isReceipt ? [$assetLine, ...$controlLines] : [...$controlLines, $assetLine],
        ]);

        return (int) $this->journalEntryService->post($business, $user, (int) $journalEntry->getKey())->getKey();
    }

    /**
     * Post a payment that is not for an invoice, such as a cash expense or a cash sale, against the account it is for.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function postAgainstOffsetAccount(Business $business, User $user, array $attributes, int $assetAccountId, int $offsetAccountId, int $amountCents, int $documentId, int $periodId): int
    {
        if ($offsetAccountId === $assetAccountId || ! $business->accounts()->whereKey($offsetAccountId)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['offset_account_id' => ['Choose another active account from this business for what the money is for.']]);
        }

        $isReceipt = $attributes['direction'] === 'in';
        $reference = $attributes['reference'] ?? null;
        $label = $attributes['notes'] ?? $reference ?? ($isReceipt ? 'Money received' : 'Money paid');
        $assetLine = ['account_id' => $assetAccountId, 'counterparty_id' => null, 'description' => mb_substr((string) $label, 0, 255), 'debit' => $isReceipt ? $this->fromCents($amountCents) : '0.00', 'credit' => $isReceipt ? '0.00' : $this->fromCents($amountCents)];
        $offsetLine = ['account_id' => $offsetAccountId, 'counterparty_id' => null, 'description' => mb_substr((string) $label, 0, 255), 'debit' => $isReceipt ? '0.00' : $this->fromCents($amountCents), 'credit' => $isReceipt ? $this->fromCents($amountCents) : '0.00'];
        $journalEntry = $this->journalEntryService->createDraft($business, $user, [
            'accounting_period_id' => $periodId,
            'entry_date' => $attributes['paid_on'],
            'reference' => $reference === null ? null : mb_substr($reference, 0, 100),
            'description' => mb_substr(($isReceipt ? 'Money received' : 'Money paid').($reference === null ? '' : ' '.$reference), 0, 255),
            'document_id' => $documentId,
            'lines' => $isReceipt ? [$assetLine, $offsetLine] : [$offsetLine, $assetLine],
        ]);

        return (int) $this->journalEntryService->post($business, $user, (int) $journalEntry->getKey())->getKey();
    }

    /**
     * A payment recorded from an imported bank statement line uses that line instead of creating
     * a second one, and reconciles it with the new journal entry.
     */
    private function reconcileStatementLine(BankAccount $bankAccount, int $transactionId, JournalEntry $entry, int $signedAmountCents, string $paidOn): int
    {
        $transaction = $bankAccount->transactions()->whereKey($transactionId)->lockForUpdate()->first();

        if ($transaction === null || $transaction->source !== 'import' || $transaction->is_reconciled
            || $this->toCents((string) $transaction->amount) !== $signedAmountCents || $transaction->transaction_date->toDateString() !== $paidOn) {
            throw ValidationException::withMessages(['bank_transaction_id' => ['This bank statement line is already booked, or its amount and date do not match the payment.']]);
        }

        $transaction->forceFill(['journal_entry_id' => $entry->getKey(), 'is_reconciled' => true, 'reconciled_at' => now()])->save();

        return (int) $transaction->getKey();
    }

    /** @param list<array{invoice_id: int|string, amount: string|int|float}> $allocations */
    private function validateAllocations(Business $business, array $allocations, int $paymentAmountCents, string $direction, string $paidOn): void
    {
        $allocationCents = 0;
        $invoiceIds = array_map(fn (array $allocation): int => (int) $allocation['invoice_id'], $allocations);
        sort($invoiceIds);

        foreach ($invoiceIds as $invoiceId) {
            $invoice = $this->invoiceRepository->findForPayment($business, $invoiceId);
            $expectedDirection = $invoice->kind === 'sale' ? 'in' : 'out';

            if ($invoice->isCreditNote() || ! in_array($invoice->status, ['issued', 'partially_paid'], true)) {
                throw ValidationException::withMessages(['allocations' => ['Only issued or partially paid invoices can receive allocations.']]);
            }

            if ($direction !== $expectedDirection) {
                throw ValidationException::withMessages(['direction' => ['Payment direction must match the selected invoice.']]);
            }

            if ($invoice->currency !== $business->currency || $invoice->issued_on->toDateString() > $paidOn) {
                throw ValidationException::withMessages(['allocations' => ['Invoice currency must match the business, and payment cannot predate the invoice.']]);
            }

            $existingCents = $invoice->payments->sum(fn (Payment $payment): int => $this->toCents($payment->pivot->amount));
            $matchingAllocations = array_filter($allocations, fn (array $allocation): bool => (int) $allocation['invoice_id'] === $invoiceId);
            $requestedCents = array_sum(array_map(fn (array $allocation): int => $this->toCents($allocation['amount']), $matchingAllocations));

            if ($requestedCents <= 0 || $existingCents + $requestedCents > $this->toCents($invoice->gross_amount) - $this->toCents((string) $invoice->credited_total)) {
                throw ValidationException::withMessages(['allocations' => ['An invoice allocation must be positive and cannot exceed the invoice balance.']]);
            }

            $allocationCents += $requestedCents;
        }

        if ($allocationCents > $paymentAmountCents) {
            throw ValidationException::withMessages(['allocations' => ['Invoice allocations cannot exceed the payment amount.']]);
        }
    }

    private function toCents(string|int|float $amount): int
    {
        $value = is_float($amount) ? number_format($amount, 2, '.', '') : (string) $amount;
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '+-');
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
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
