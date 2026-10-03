<?php

namespace App\Services\Invoicing;

use App\Models\Business;
use App\Models\ComplianceRuleSet;
use App\Models\Invoice;
use App\Models\User;
use App\Repositories\Contracts\ComplianceRuleSetRepository;
use App\Repositories\Contracts\DocumentRepository;
use App\Repositories\Contracts\InvoiceRepository;
use App\Repositories\Contracts\JournalEntryRepository;
use App\Repositories\Contracts\PaymentRepository;
use App\Services\Accounting\JournalEntryService;
use App\Services\Accounting\PostingAccountService;
use App\Services\Inventory\InventoryService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceService
{
    public function __construct(
        private InvoiceRepository $invoiceRepository,
        private DocumentRepository $documentRepository,
        private ComplianceRuleSetRepository $complianceRuleSetRepository,
        private JournalEntryRepository $journalEntryRepository,
        private PaymentRepository $paymentRepository,
        private InventoryService $inventoryService,
        private JournalEntryService $journalEntryService,
        private PostingAccountService $postingAccountService,
    ) {}

    /** @return LengthAwarePaginator<int, Invoice> */
    public function listForBusiness(Business $business, ?string $kind = null): LengthAwarePaginator
    {
        return $this->invoiceRepository->listForBusiness($business, $kind);
    }

    public function findForBusiness(Business $business, int $invoiceId): Invoice
    {
        return $this->invoiceRepository->findForDisplay($business, $invoiceId);
    }

    /** @return array<string, mixed> */
    public function taxCodes(Business $business, string $date): array
    {
        $ruleSet = $this->complianceRuleSetRepository->findEffective('XK', 'vat', $date);

        if ($ruleSet === null) {
            throw ValidationException::withMessages(['issued_on' => ['No approved Kosovo VAT rules are effective for this date.']]);
        }

        return [
            'rule_set_id' => $ruleSet->getKey(),
            'version' => $ruleSet->version,
            'source_title' => $ruleSet->source_title,
            'source_url' => $ruleSet->source_url,
            'codes' => $this->codesFromRuleSet($ruleSet),
        ];
    }

    /** @param array<string, mixed> $attributes */
    public function createDraft(Business $business, int $userId, array $attributes): Invoice
    {
        if ($attributes['kind'] === 'purchase' && $this->invoiceRepository->invoiceNumberExists($business, $attributes['kind'], $attributes['invoice_number'])) {
            throw ValidationException::withMessages(['invoice_number' => ['This invoice number has already been used for this business.']]);
        }

        $counterparty = $business->counterparties()->whereKey($attributes['counterparty_id'])->first();
        $allowedTypes = $attributes['kind'] === 'sale' ? ['customer', 'both'] : ['supplier', 'both'];

        if ($counterparty === null || ! in_array($counterparty->type, $allowedTypes, true)) {
            throw ValidationException::withMessages(['counterparty_id' => ['Choose a customer or supplier that belongs to this business.']]);
        }

        if (isset($attributes['document_id']) && ! $this->documentRepository->isInvoiceForBusiness($business, (int) $attributes['document_id'])) {
            throw ValidationException::withMessages(['document_id' => ['Choose an invoice document that belongs to this business.']]);
        }

        if (! in_array($business->vat_status, ['registered', 'not_registered'], true)) {
            throw ValidationException::withMessages(['vat_status' => ['Set this business as VAT registered or not registered before creating an invoice.']]);
        }

        $ruleSet = $this->complianceRuleSetRepository->findEffective('XK', 'vat', $attributes['issued_on']);

        if ($ruleSet === null) {
            throw ValidationException::withMessages(['issued_on' => ['No approved Kosovo VAT rules are effective for this date.']]);
        }

        $vatCodes = collect($this->codesFromRuleSet($ruleSet))->keyBy('code');
        $lineAttributes = [];
        $netCents = 0;
        $taxCents = 0;

        foreach (array_values($attributes['lines']) as $position => $line) {
            $inventoryItemId = isset($line['inventory_item_id']) ? (int) $line['inventory_item_id'] : null;
            $warehouseId = isset($line['warehouse_id']) ? (int) $line['warehouse_id'] : null;

            if (($inventoryItemId === null) !== ($warehouseId === null)) {
                throw ValidationException::withMessages(["lines.{$position}.inventory_item_id" => ['Choose both an inventory item and warehouse, or leave both blank.']]);
            }

            if ($inventoryItemId !== null) {
                if (! $business->inventoryItems()->whereKey($inventoryItemId)->where('is_active', true)->exists()) {
                    throw ValidationException::withMessages(["lines.{$position}.inventory_item_id" => ['Choose an active inventory item that belongs to this business.']]);
                }

                if (! $business->warehouses()->whereKey($warehouseId)->where('is_active', true)->exists()) {
                    throw ValidationException::withMessages(["lines.{$position}.warehouse_id" => ['Choose an active warehouse that belongs to this business.']]);
                }
            }

            $lineBaseNetCents = $this->netAmountCents($line['quantity'], $line['unit_price']);
            $discountCents = $this->toCents($line['discount_amount'] ?? '0.00');

            if ($discountCents > $lineBaseNetCents) {
                throw ValidationException::withMessages(["lines.{$position}.discount_amount" => ['A line discount cannot exceed the line amount before VAT.']]);
            }

            $lineNetCents = $lineBaseNetCents - $discountCents;
            $taxCode = $vatCodes->get($line['vat_code']);

            if ($taxCode === null) {
                throw ValidationException::withMessages(["lines.{$position}.vat_code" => ['Choose a VAT code from the approved rules effective on the invoice date.']]);
            }

            if ($business->vat_status === 'not_registered' && $taxCode['treatment'] !== 'non_vat') {
                throw ValidationException::withMessages(["lines.{$position}.vat_code" => ['A non-registered business must use a non-VAT tax code.']]);
            }

            if ($business->vat_status === 'registered' && $taxCode['treatment'] === 'non_vat') {
                throw ValidationException::withMessages(["lines.{$position}.vat_code" => ['A VAT-registered business cannot use a non-VAT tax code.']]);
            }

            $taxRateScaled = $this->toScaledFour($taxCode['rate_percent']);
            $lineTaxCents = $taxCode['treatment'] === 'taxable' ? $this->taxAmountCents($lineNetCents, $taxRateScaled) : 0;
            $netCents += $lineNetCents;
            $taxCents += $lineTaxCents;
            $lineAttributes[] = [
                'business_id' => $business->getKey(),
                'position' => $position + 1,
                'description' => $line['description'],
                'inventory_item_id' => $inventoryItemId,
                'warehouse_id' => $warehouseId,
                'quantity' => $line['quantity'],
                'unit' => $line['unit'] ?? null,
                'unit_price' => $line['unit_price'],
                'discount_amount' => $this->fromCents($discountCents),
                'vat_code' => $taxCode['code'],
                'net_amount' => $this->fromCents($lineNetCents),
                'tax_amount' => $this->fromCents($lineTaxCents),
                'gross_amount' => $this->fromCents($lineNetCents + $lineTaxCents),
                'tax_snapshot' => [
                    'code' => $taxCode['code'],
                    'label' => $taxCode['label'],
                    'rate_percent' => $taxCode['rate_percent'],
                    'treatment' => $taxCode['treatment'],
                ],
            ];
        }

        unset($attributes['lines']);
        $attributes['created_by'] = $userId;
        $attributes['business_id'] = $business->getKey();
        $attributes['currency'] = $business->currency;
        $attributes['status'] = 'draft';
        $attributes['net_amount'] = $this->fromCents($netCents);
        $attributes['tax_amount'] = $this->fromCents($taxCents);
        $attributes['gross_amount'] = $this->fromCents($netCents + $taxCents);
        $attributes['compliance_rule_set_id'] = $ruleSet->getKey();
        $attributes['tax_snapshot'] = [
            'jurisdiction' => $ruleSet->jurisdiction,
            'domain' => $ruleSet->domain,
            'version' => $ruleSet->version,
            'valid_from' => $ruleSet->valid_from->toDateString(),
            'valid_to' => $ruleSet->valid_to?->toDateString(),
            'source_title' => $ruleSet->source_title,
            'source_url' => $ruleSet->source_url,
        ];

        return DB::transaction(function () use ($business, $userId, $attributes, $lineAttributes): Invoice {
            if ($attributes['kind'] === 'sale' && ($attributes['document_type'] ?? 'invoice') === 'invoice') {
                $attributes['invoice_number'] = $this->invoiceRepository->nextSalesInvoiceNumber(
                    $business,
                    Carbon::parse($attributes['issued_on'])->year,
                );
            }

            $invoice = $this->invoiceRepository->createDraft($business, $attributes, $lineAttributes);
            $business->auditLogs()->create([
                'user_id' => $userId,
                'action' => 'invoice.draft_created',
                'subject_type' => Invoice::class,
                'subject_id' => $invoice->getKey(),
                'new_values' => $invoice->only(['kind', 'invoice_number', 'net_amount', 'tax_amount', 'gross_amount']),
            ]);

            return $invoice;
        });
    }

    public function issueDraft(Business $business, User $user, int $invoiceId, int $journalEntryId): Invoice
    {
        return DB::transaction(function () use ($business, $user, $invoiceId, $journalEntryId): Invoice {
            $invoice = $this->invoiceRepository->findForIssuing($business, $invoiceId);

            if ($invoice->status !== 'draft') {
                throw ValidationException::withMessages(['invoice' => ['Only draft invoices can be issued.']]);
            }

            if ($invoice->kind === 'purchase' && $invoice->document_id === null) {
                throw ValidationException::withMessages(['document_id' => ['Attach the supplier bill before issuing this purchase invoice.']]);
            }

            $effectiveRules = $this->complianceRuleSetRepository->findEffective(
                'XK',
                'vat',
                $invoice->issued_on->toDateString(),
            );

            if ($effectiveRules === null || (int) $invoice->compliance_rule_set_id !== (int) $effectiveRules->getKey()) {
                throw ValidationException::withMessages(['invoice' => ['The invoice does not have a snapshot of the approved VAT rules effective on its date. Recreate the draft using the current approved rules.']]);
            }

            if ($business->vat_status === 'unknown') {
                throw ValidationException::withMessages(['vat_status' => ['Set the business VAT registration status before issuing an invoice.']]);
            }

            $journalEntry = $this->journalEntryRepository->findForPosting($business, $journalEntryId);

            if ($journalEntry->status !== 'posted' || $journalEntry->entry_date->toDateString() !== $invoice->issued_on->toDateString()) {
                throw ValidationException::withMessages(['journal_entry_id' => ['Choose a posted journal entry dated on the invoice date.']]);
            }

            if ($business->invoices()->where('journal_entry_id', $journalEntry->getKey())->exists()) {
                throw ValidationException::withMessages(['journal_entry_id' => ['This journal entry is already linked to an invoice.']]);
            }

            if ($this->paymentRepository->hasPaymentForJournalEntry($business, (int) $journalEntry->getKey())) {
                throw ValidationException::withMessages(['journal_entry_id' => ['This journal entry already records a payment and cannot also issue an invoice.']]);
            }

            if ($invoice->isCreditNote()) {
                $this->assertCreditFitsOriginal($business, $invoice);
            }

            $this->inventoryService->recordInvoiceMovements($business, $invoice, $user);

            $grossCents = $this->toCents($invoice->gross_amount);
            $counterpartyMovementCents = 0;
            $debitCents = 0;
            $creditCents = 0;

            foreach ($journalEntry->lines as $line) {
                $debit = $this->toCents($line->debit);
                $credit = $this->toCents($line->credit);
                $debitCents += $debit;
                $creditCents += $credit;

                if ((int) $line->counterparty_id === (int) $invoice->counterparty_id) {
                    $counterpartyMovementCents += ($invoice->kind === 'sale') !== $invoice->isCreditNote() ? $debit - $credit : $credit - $debit;
                }
            }

            if ($journalEntry->lines->count() < 2 || $debitCents !== $creditCents || $counterpartyMovementCents !== $grossCents) {
                throw ValidationException::withMessages(['journal_entry_id' => ['The posted journal entry must balance and contain the invoice total on the matching customer or supplier account.']]);
            }

            $issuedInvoice = $this->invoiceRepository->markIssued($invoice, $journalEntry, $user);

            if ($invoice->isCreditNote()) {
                $this->applyCreditToOriginal($business, $user, $invoice);
            }
            $business->auditLogs()->create([
                'user_id' => $user->getKey(),
                'action' => 'invoice.issued',
                'subject_type' => Invoice::class,
                'subject_id' => $invoice->getKey(),
                'old_values' => ['status' => 'draft'],
                'new_values' => [
                    'status' => 'issued',
                    'journal_entry_id' => $journalEntry->getKey(),
                    'compliance_rule_set_id' => $effectiveRules->getKey(),
                    'compliance_version' => $effectiveRules->version,
                ],
            ]);

            return $issuedInvoice;
        });
    }

    /**
     * Issue a draft invoice by generating and posting its journal entry from the business posting accounts.
     */
    public function issueWithGeneratedJournal(Business $business, User $user, int $invoiceId): Invoice
    {
        return DB::transaction(function () use ($business, $user, $invoiceId): Invoice {
            $invoice = $this->invoiceRepository->findForIssuing($business, $invoiceId);

            if ($invoice->status !== 'draft') {
                throw ValidationException::withMessages(['invoice' => ['Only draft invoices can be issued.']]);
            }

            $grossCents = $this->toCents($invoice->gross_amount);
            $netCents = $this->toCents($invoice->net_amount);
            $taxCents = $this->toCents($invoice->tax_amount);

            if ($grossCents <= 0) {
                throw ValidationException::withMessages(['invoice' => ['A zero-value invoice cannot be posted automatically.']]);
            }

            $issuedOn = $invoice->issued_on->toDateString();
            $periodId = $this->journalEntryService->findOpenPeriodIdForDate($business, $issuedOn);

            if ($periodId === null) {
                throw ValidationException::withMessages(['issued_on' => ['Open an accounting period that contains the invoice date before issuing it.']]);
            }

            $isSale = $invoice->kind === 'sale';
            $purposes = $isSale ? ['accounts_receivable', 'sales_revenue'] : ['accounts_payable', 'purchase_expense'];

            if ($taxCents > 0) {
                $purposes[] = $isSale ? 'vat_output' : 'vat_input';
            }

            $accounts = $this->postingAccountService->requireAccounts($business, $purposes);
            $counterpartyName = $invoice->counterparty->name;
            $lines = [];

            if ($isSale) {
                $lines[] = $this->journalLine($accounts['accounts_receivable']->getKey(), $invoice->counterparty_id, "Receivable from {$counterpartyName}", $grossCents, 0);
                $lines[] = $this->journalLine($accounts['sales_revenue']->getKey(), null, "Sales invoice {$invoice->invoice_number}", 0, $netCents);

                if ($taxCents > 0) {
                    $lines[] = $this->journalLine($accounts['vat_output']->getKey(), null, "Output VAT on {$invoice->invoice_number}", 0, $taxCents);
                }
            } else {
                $lines[] = $this->journalLine($accounts['purchase_expense']->getKey(), null, "Purchase invoice {$invoice->invoice_number}", $netCents, 0);

                if ($taxCents > 0) {
                    $lines[] = $this->journalLine($accounts['vat_input']->getKey(), null, "Input VAT on {$invoice->invoice_number}", $taxCents, 0);
                }

                $lines[] = $this->journalLine($accounts['accounts_payable']->getKey(), $invoice->counterparty_id, "Payable to {$counterpartyName}", 0, $grossCents);
            }

            if ($invoice->isCreditNote()) {
                $lines = array_map(fn (array $line): array => [...$line, 'debit' => $line['credit'], 'credit' => $line['debit'], 'description' => mb_substr('Credit note: '.$line['description'], 0, 255)], $lines);
            }

            $journalEntry = $this->journalEntryService->createDraft($business, $user, [
                'accounting_period_id' => $periodId,
                'entry_date' => $issuedOn,
                'reference' => $invoice->invoice_number,
                'description' => ($isSale ? 'Sales invoice ' : 'Purchase invoice ').$invoice->invoice_number.' – '.$counterpartyName,
                'document_id' => $invoice->document_id,
                'lines' => array_values(array_filter($lines, fn (array $line): bool => $line['debit'] !== '0.00' || $line['credit'] !== '0.00')),
            ]);
            $this->journalEntryService->post($business, $user, (int) $journalEntry->getKey());

            return $this->issueDraft($business, $user, $invoiceId, (int) $journalEntry->getKey());
        });
    }

    /**
     * Draft a credit note that corrects part or all of an issued invoice. It is issued like an invoice and posts the reverse entry.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createCreditNote(Business $business, User $user, int $invoiceId, array $attributes): Invoice
    {
        return DB::transaction(function () use ($business, $user, $invoiceId, $attributes): Invoice {
            $original = $this->invoiceRepository->findForIssuing($business, $invoiceId);

            if ($original->isCreditNote() || ! in_array($original->status, ['issued', 'partially_paid', 'paid'], true)) {
                throw ValidationException::withMessages(['invoice' => ['Credit notes can only be made for issued, partially paid, or paid invoices.']]);
            }

            if ($original->kind === 'sale') {
                $attributes['invoice_number'] = 'CN-'.$original->invoice_number.'-'.($original->creditNotes()->count() + 1);
            } elseif (empty($attributes['invoice_number'])) {
                throw ValidationException::withMessages(['invoice_number' => ["Enter the supplier's credit note number."]]);
            }

            $creditNote = $this->createDraft($business, (int) $user->getKey(), [
                ...$attributes,
                'kind' => $original->kind,
                'document_type' => 'credit_note',
                'credited_invoice_id' => $original->getKey(),
                'counterparty_id' => $original->counterparty_id,
            ]);
            $remainingCents = $this->toCents((string) $original->gross_amount) - $this->toCents((string) $original->credited_total);

            if ($this->toCents((string) $creditNote->gross_amount) > $remainingCents) {
                throw ValidationException::withMessages(['lines' => ['The credit note cannot exceed what is left to credit on the invoice ('.$this->fromCents($remainingCents).').']]);
            }

            return $creditNote;
        });
    }

    private function assertCreditFitsOriginal(Business $business, Invoice $creditNote): void
    {
        $original = $business->invoices()->whereKey($creditNote->credited_invoice_id)->lockForUpdate()->firstOrFail();

        if (! in_array($original->status, ['issued', 'partially_paid', 'paid'], true)) {
            throw ValidationException::withMessages(['invoice' => ['The credited invoice is no longer open to credit.']]);
        }

        $remainingCents = $this->toCents((string) $original->gross_amount) - $this->toCents((string) $original->credited_total);

        if ($this->toCents((string) $creditNote->gross_amount) > $remainingCents) {
            throw ValidationException::withMessages(['invoice' => ['The credit note exceeds what is left to credit on the invoice ('.$this->fromCents($remainingCents).').']]);
        }
    }

    private function applyCreditToOriginal(Business $business, User $user, Invoice $creditNote): void
    {
        $original = $this->invoiceRepository->findForPayment($business, (int) $creditNote->credited_invoice_id);
        $creditedCents = $this->toCents((string) $original->credited_total) + $this->toCents((string) $creditNote->gross_amount);
        $paidCents = (int) $original->payments->sum(fn ($payment): int => $this->toCents((string) $payment->pivot->amount));
        $grossCents = $this->toCents((string) $original->gross_amount);
        $oldStatus = $original->status;
        $newStatus = $paidCents + $creditedCents >= $grossCents ? 'paid' : ($paidCents + $creditedCents > 0 ? 'partially_paid' : 'issued');
        $original->forceFill(['credited_total' => $this->fromCents($creditedCents), 'status' => $newStatus])->save();
        $business->auditLogs()->create([
            'user_id' => $user->getKey(),
            'action' => 'invoice.credited',
            'subject_type' => Invoice::class,
            'subject_id' => $original->getKey(),
            'old_values' => ['status' => $oldStatus],
            'new_values' => ['status' => $newStatus, 'credited_total' => $this->fromCents($creditedCents), 'credit_note_id' => $creditNote->getKey()],
        ]);
    }

    /**
     * Delete a draft. A sales draft already holds a number, so the deleted number is kept in the audit log to explain the gap.
     */
    public function deleteDraft(Business $business, User $user, int $invoiceId): void
    {
        DB::transaction(function () use ($business, $user, $invoiceId): void {
            $invoice = $this->invoiceRepository->findForIssuing($business, $invoiceId);

            if ($invoice->status !== 'draft') {
                throw ValidationException::withMessages(['invoice' => ['Only draft invoices can be deleted. Cancel or credit an issued invoice instead.']]);
            }

            $business->auditLogs()->create([
                'user_id' => $user->getKey(),
                'action' => 'invoice.draft_deleted',
                'subject_type' => Invoice::class,
                'subject_id' => $invoice->getKey(),
                'old_values' => $invoice->only(['kind', 'invoice_number', 'issued_on', 'gross_amount']),
            ]);
            $invoice->delete();
        });
    }

    /**
     * Cancel an issued, unpaid invoice by posting a reversal of its journal entry and reversing its stock movements.
     *
     * @param  array{cancelled_on: string, cancellation_reason: string}  $attributes
     */
    public function cancel(Business $business, User $user, int $invoiceId, array $attributes): Invoice
    {
        return DB::transaction(function () use ($business, $user, $invoiceId, $attributes): Invoice {
            $invoice = $this->invoiceRepository->findForIssuing($business, $invoiceId);

            if ($invoice->isCreditNote()) {
                throw ValidationException::withMessages(['invoice' => ['A credit note cannot be cancelled. Issue a new invoice to reverse it.']]);
            }

            if ($this->toCents((string) $invoice->credited_total) > 0) {
                throw ValidationException::withMessages(['invoice' => ['An invoice with credit notes cannot be cancelled.']]);
            }

            if ($invoice->status !== 'issued') {
                throw ValidationException::withMessages(['invoice' => [in_array($invoice->status, ['partially_paid', 'paid'], true)
                    ? 'An invoice with recorded payments cannot be cancelled.'
                    : 'Only issued invoices can be cancelled.']]);
            }

            if ($invoice->payments()->exists()) {
                throw ValidationException::withMessages(['invoice' => ['An invoice with recorded payments cannot be cancelled.']]);
            }

            $cancelledOn = $attributes['cancelled_on'];

            if ($cancelledOn < $invoice->issued_on->toDateString()) {
                throw ValidationException::withMessages(['cancelled_on' => ['The cancellation date cannot be before the invoice date.']]);
            }

            $periodId = $this->journalEntryService->findOpenPeriodIdForDate($business, $cancelledOn);

            if ($periodId === null) {
                throw ValidationException::withMessages(['cancelled_on' => ['Open an accounting period that contains the cancellation date.']]);
            }

            $reversal = $this->journalEntryService->createReversalDraft($business, $user, (int) $invoice->journal_entry_id, [
                'accounting_period_id' => $periodId,
                'entry_date' => $cancelledOn,
                'reference' => mb_substr('CANCEL-'.$invoice->invoice_number, 0, 100),
            ]);
            $reversal = $this->journalEntryService->post($business, $user, (int) $reversal->getKey());
            $this->inventoryService->reverseInvoiceMovements($business, $invoice, $user, $cancelledOn);

            $cancelledInvoice = $this->invoiceRepository->markCancelled($invoice, $reversal, $user, $attributes);
            $business->auditLogs()->create([
                'user_id' => $user->getKey(),
                'action' => 'invoice.cancelled',
                'subject_type' => Invoice::class,
                'subject_id' => $invoice->getKey(),
                'old_values' => ['status' => 'issued'],
                'new_values' => [
                    'status' => 'cancelled',
                    'cancelled_on' => $cancelledOn,
                    'cancellation_reason' => $attributes['cancellation_reason'],
                    'cancellation_journal_entry_id' => $reversal->getKey(),
                ],
            ]);

            return $cancelledInvoice;
        });
    }

    /** @return array{account_id: int, counterparty_id: int|null, description: string, debit: string, credit: string} */
    private function journalLine(int $accountId, ?int $counterpartyId, string $description, int $debitCents, int $creditCents): array
    {
        return [
            'account_id' => $accountId,
            'counterparty_id' => $counterpartyId,
            'description' => mb_substr($description, 0, 255),
            'debit' => $this->fromCents($debitCents),
            'credit' => $this->fromCents($creditCents),
        ];
    }

    /** @return list<array{code: string, label: string, rate_percent: string, treatment: string}> */
    private function codesFromRuleSet(ComplianceRuleSet $ruleSet): array
    {
        $configuration = $ruleSet->rules->firstWhere('rule_key', 'vat_codes')?->configuration;
        $codes = $configuration['codes'] ?? null;

        if (! is_array($codes) || $codes === []) {
            throw ValidationException::withMessages(['compliance_rule_set' => ['The approved VAT rule set must include a vat_codes rule with one or more codes.']]);
        }

        $normalised = [];
        $seenCodes = [];

        foreach ($codes as $code) {
            if (! is_array($code)
                || ! isset($code['code'], $code['label'], $code['rate_percent'], $code['treatment'])
                || ! is_string($code['code'])
                || ! is_string($code['label'])
                || ! is_numeric($code['rate_percent'])
                || ! in_array($code['treatment'], ['taxable', 'exempt', 'zero_rated', 'non_vat'], true)) {
                throw ValidationException::withMessages(['compliance_rule_set' => ['Each VAT code needs a code, label, rate_percent, and supported treatment.']]);
            }

            $rateScaled = $this->toScaledFour((string) $code['rate_percent']);

            if ($rateScaled < 0 || $rateScaled > 1_000_000 || (in_array($code['treatment'], ['exempt', 'zero_rated', 'non_vat'], true) && $rateScaled !== 0)) {
                throw ValidationException::withMessages(['compliance_rule_set' => ['VAT code rates must be from zero through one hundred percent; non-taxable codes must use zero.']]);
            }

            if (isset($seenCodes[$code['code']])) {
                throw ValidationException::withMessages(['compliance_rule_set' => ['VAT code values must be unique within the rule set.']]);
            }

            $seenCodes[$code['code']] = true;
            $normalised[] = [
                'code' => $code['code'],
                'label' => $code['label'],
                'rate_percent' => intdiv($rateScaled, 10_000).'.'.str_pad((string) ($rateScaled % 10_000), 4, '0', STR_PAD_LEFT),
                'treatment' => $code['treatment'],
            ];
        }

        return $normalised;
    }

    private function toCents(string|float|int $amount): int
    {
        $value = is_float($amount) ? number_format($amount, 2, '.', '') : (string) $amount;
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '+-');
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $cents = ((int) $whole * 100) + (int) substr(str_pad($fraction, 2, '0'), 0, 2);

        return $negative ? -$cents : $cents;
    }

    private function netAmountCents(string|float|int $quantity, string|float|int $unitPrice): int
    {
        $quantityScaled = $this->toScaledFour($quantity);
        $unitPriceScaled = $this->toScaledFour($unitPrice);
        $productScaledEight = $quantityScaled * $unitPriceScaled;
        $wholeCents = intdiv($productScaledEight, 1_000_000);
        $fractionalCents = $productScaledEight % 1_000_000;

        return $wholeCents + ($fractionalCents >= 500_000 ? 1 : 0);
    }

    private function taxAmountCents(int $netCents, int $ratePercentScaledFour): int
    {
        $product = $netCents * $ratePercentScaledFour;
        $wholeCents = intdiv($product, 1_000_000);
        $fractionalCents = $product % 1_000_000;

        return $wholeCents + ($fractionalCents >= 500_000 ? 1 : 0);
    }

    private function toScaledFour(string|float|int $amount): int
    {
        $value = is_float($amount) ? number_format($amount, 4, '.', '') : (string) $amount;
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return ((int) $whole * 10_000) + (int) substr(str_pad($fraction, 4, '0'), 0, 4);
    }

    private function fromCents(int $amount): string
    {
        return intdiv($amount, 100).'.'.str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
    }
}
