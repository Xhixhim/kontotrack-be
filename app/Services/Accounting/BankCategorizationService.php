<?php

namespace App\Services\Accounting;

use App\Models\BankAccount;
use App\Models\BankCategorizationRule;
use App\Models\BankTransaction;
use App\Models\Business;
use App\Models\Counterparty;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Categorises bank statement lines (what each receipt or payment is for), learns rules from the
 * bookkeeper's choices, and books a categorised line into the ledger as a payment.
 */
class BankCategorizationService
{
    /** Words that mark a bank's own charges on Kosovo statements. */
    private const BANK_FEE_WORDS = ['komision', 'provizion', 'tarif', 'mirembajtje', 'bank fee', 'bank charge', 'service charge'];

    public function __construct(private PaymentService $paymentService) {}

    /** @return Collection<int, BankCategorizationRule> */
    public function listRules(Business $business): Collection
    {
        return $business->bankCategorizationRules()->with(['account', 'counterparty'])->orderBy('match_text')->get();
    }

    /** @param array{match_text: string, direction: string, category: string, account_id?: int|null, counterparty_id?: int|null, is_active?: bool} $attributes */
    public function createRule(Business $business, User $user, array $attributes): BankCategorizationRule
    {
        $attributes = $this->validatedRule($business, $attributes);

        return DB::transaction(function () use ($business, $user, $attributes): BankCategorizationRule {
            $rule = $business->bankCategorizationRules()->create([...$attributes, 'created_by' => $user->getKey()]);
            $this->audit($business, $user, 'bank_rule.created', $rule, [], $rule->only(['match_text', 'direction', 'category', 'account_id', 'counterparty_id', 'is_active']));

            return $rule->load(['account', 'counterparty']);
        });
    }

    /** @param array<string, mixed> $attributes */
    public function updateRule(Business $business, User $user, int $ruleId, array $attributes): BankCategorizationRule
    {
        return DB::transaction(function () use ($business, $user, $ruleId, $attributes): BankCategorizationRule {
            $rule = $business->bankCategorizationRules()->whereKey($ruleId)->lockForUpdate()->firstOrFail();
            $before = $rule->only(['match_text', 'direction', 'category', 'account_id', 'counterparty_id', 'is_active']);
            $rule->fill($this->validatedRule($business, [...$before, ...$attributes], $rule->getKey()))->save();
            $after = $rule->only(array_keys($before));
            $changed = array_keys(array_diff_assoc(array_map('strval', $after), array_map('strval', $before)));

            if ($changed !== []) {
                $this->audit($business, $user, 'bank_rule.updated', $rule, array_intersect_key($before, array_flip($changed)), array_intersect_key($after, array_flip($changed)));
            }

            return $rule->load(['account', 'counterparty']);
        });
    }

    public function deleteRule(Business $business, User $user, int $ruleId): void
    {
        DB::transaction(function () use ($business, $user, $ruleId): void {
            $rule = $business->bankCategorizationRules()->whereKey($ruleId)->lockForUpdate()->firstOrFail();
            // Lines it categorised keep their category; they just no longer point at the rule.
            BankTransaction::query()->where('business_id', $business->getKey())->where('categorization_rule_id', $rule->getKey())->update(['categorization_rule_id' => null]);
            $this->audit($business, $user, 'bank_rule.deleted', $rule, $rule->only(['match_text', 'direction', 'category', 'account_id', 'counterparty_id']), []);
            $rule->delete();
        });
    }

    /**
     * Sets (or clears, with a null category) what a statement line is for. With $rememberAs, also
     * saves a rule so future lines containing that text get the same category.
     *
     * @param  array{category: string|null, account_id?: int|null, counterparty_id?: int|null}  $attributes
     */
    public function categorize(Business $business, User $user, int $bankAccountId, int $transactionId, array $attributes, ?string $rememberAs = null): BankTransaction
    {
        return DB::transaction(function () use ($business, $user, $bankAccountId, $transactionId, $attributes, $rememberAs): BankTransaction {
            $transaction = $this->openStatementLine($business, $bankAccountId, $transactionId);
            $category = $attributes['category'] ?? null;
            $accountId = $attributes['account_id'] ?? null;
            $counterpartyId = $attributes['counterparty_id'] ?? null;

            if ($category !== null) {
                [$accountId, $counterpartyId] = $this->validatedTarget($business, $transaction->bankAccount, $category, $accountId, $counterpartyId, (string) $transaction->amount);
            } else {
                [$accountId, $counterpartyId] = [null, null];
            }

            $before = $transaction->only(['category', 'account_id', 'counterparty_id']);
            $transaction->forceFill([
                'category' => $category,
                'account_id' => $accountId,
                'counterparty_id' => $counterpartyId,
                'categorization_rule_id' => null,
                'categorized_by' => $category === null ? null : $user->getKey(),
                'categorized_at' => $category === null ? null : now(),
            ])->save();
            $this->audit($business, $user, 'bank_transaction.categorized', $transaction, $before, $transaction->only(['category', 'account_id', 'counterparty_id']));

            if ($category !== null && $rememberAs !== null) {
                $direction = BankCategories::ALL[$category]['direction'] === 'any' ? (str_starts_with((string) $transaction->amount, '-') ? 'out' : 'in') : BankCategories::ALL[$category]['direction'];
                $matchText = BankCategories::normalize($rememberAs);

                if (! str_contains(BankCategories::normalize((string) $transaction->description), $matchText)) {
                    throw ValidationException::withMessages(['remember_as' => ['The rule text must appear in this line\'s description.']]);
                }

                $existing = $business->bankCategorizationRules()->where('match_text', $matchText)->where('direction', $direction)->first();
                $ruleAttributes = ['match_text' => $matchText, 'direction' => $direction, 'category' => $category, 'account_id' => $accountId, 'counterparty_id' => $counterpartyId, 'is_active' => true];
                $existing === null ? $this->createRule($business, $user, $ruleAttributes) : $this->updateRule($business, $user, (int) $existing->getKey(), $ruleAttributes);
            }

            return $this->loaded($transaction);
        });
    }

    /**
     * Suggests a category for uncategorised, unbooked statement lines: first from the business's
     * rules (most specific text wins), then from a customer or supplier name in the description,
     * then from bank-fee wording. Returns how many lines got a suggestion.
     *
     * @param  iterable<BankTransaction>|null  $transactions  defaults to every open line of the bank account
     */
    public function applyRules(Business $business, BankAccount $bankAccount, ?iterable $transactions = null): int
    {
        $transactions ??= $bankAccount->transactions()->where('source', 'import')->where('is_reconciled', false)->whereNull('category')->get();
        $rules = $business->bankCategorizationRules()->where('is_active', true)->get()
            ->sortByDesc(fn (BankCategorizationRule $rule): int => mb_strlen($rule->match_text))->values();
        $counterparties = $business->counterparties()->where('status', 'active')->get(['id', 'type', 'name'])
            ->filter(fn (Counterparty $counterparty): bool => mb_strlen(BankCategories::normalize($counterparty->name)) >= 4)
            ->sortByDesc(fn (Counterparty $counterparty): int => mb_strlen($counterparty->name))->values();
        $suggested = 0;

        foreach ($transactions as $transaction) {
            if ($transaction->source !== 'import' || $transaction->is_reconciled || $transaction->category !== null) {
                continue;
            }

            $suggestion = $this->suggestion($transaction, $rules, $counterparties);

            if ($suggestion !== null) {
                $transaction->forceFill([...$suggestion, 'categorized_by' => null, 'categorized_at' => now()])->save();
                $suggested++;
            }
        }

        return $suggested;
    }

    /**
     * Books a categorised statement line: a customer receipt or supplier payment is allocated to
     * that party's open invoices (oldest first unless allocations are given); any other category is
     * posted against its account. The line is reconciled with the journal entry that is created.
     *
     * @param  list<array{invoice_id: int, amount: string}>|null  $allocations
     */
    public function record(Business $business, User $user, int $bankAccountId, int $transactionId, ?array $allocations = null): BankTransaction
    {
        return DB::transaction(function () use ($business, $user, $bankAccountId, $transactionId, $allocations): BankTransaction {
            $transaction = $this->openStatementLine($business, $bankAccountId, $transactionId);

            if ($transaction->category === null) {
                throw ValidationException::withMessages(['category' => ['Choose what this bank line is for before booking it.']]);
            }

            if ($transaction->document_id === null) {
                throw ValidationException::withMessages(['document_id' => ['This bank line has no statement document attached.']]);
            }

            $category = (string) $transaction->category;
            $amount = ltrim((string) $transaction->amount, '-');
            $direction = str_starts_with((string) $transaction->amount, '-') ? 'out' : 'in';
            [$accountId, $counterpartyId] = $this->validatedTarget($business, $transaction->bankAccount, $category, $transaction->account_id, $transaction->counterparty_id, (string) $transaction->amount);
            $attributes = [
                'bank_account_id' => $bankAccountId,
                'bank_transaction_id' => $transaction->getKey(),
                'document_id' => $transaction->document_id,
                'paid_on' => $transaction->transaction_date->toDateString(),
                'direction' => $direction,
                'amount' => $amount,
                'reference' => $transaction->external_id === null ? null : mb_substr((string) $transaction->external_id, 0, 120),
                'notes' => mb_substr((string) $transaction->description, 0, 255),
            ];

            if (BankCategories::ALL[$category]['needs'] === 'counterparty') {
                $attributes['allocations'] = $allocations ?? $this->oldestFirstAllocations($business, (int) $counterpartyId, $direction === 'in' ? 'sale' : 'purchase', $attributes['paid_on'], $amount);
            } else {
                $attributes['offset_account_id'] = $accountId;
            }

            $payment = $this->paymentService->createForBusiness($business, $user, $attributes);
            $business->auditLogs()->create([
                'user_id' => $user->getKey(),
                'action' => 'bank_transaction.booked',
                'subject_type' => BankTransaction::class,
                'subject_id' => $transaction->getKey(),
                'new_values' => ['category' => $category, 'payment_id' => $payment->getKey(), 'journal_entry_id' => $payment->journal_entry_id],
            ]);

            return $this->loaded($transaction->refresh());
        });
    }

    /** @return array{category: string, account_id: int|null, counterparty_id: int|null, categorization_rule_id: int|null}|null */
    private function suggestion(BankTransaction $transaction, SupportCollection $rules, SupportCollection $counterparties): ?array
    {
        $description = BankCategories::normalize((string) $transaction->description);
        $direction = str_starts_with((string) $transaction->amount, '-') ? 'out' : 'in';

        foreach ($rules as $rule) {
            if (($rule->direction === 'any' || $rule->direction === $direction) && BankCategories::fitsDirection($rule->category, (string) $transaction->amount)
                && str_contains($description, $rule->match_text)) {
                return ['category' => $rule->category, 'account_id' => $rule->account_id, 'counterparty_id' => $rule->counterparty_id, 'categorization_rule_id' => (int) $rule->getKey()];
            }
        }

        $wantedType = $direction === 'in' ? 'customer' : 'supplier';
        foreach ($counterparties as $counterparty) {
            if (in_array($counterparty->type, [$wantedType, 'both'], true) && str_contains($description, BankCategories::normalize($counterparty->name))) {
                return ['category' => $direction === 'in' ? 'customer_receipt' : 'supplier_payment', 'account_id' => null, 'counterparty_id' => (int) $counterparty->getKey(), 'categorization_rule_id' => null];
            }
        }

        if ($direction === 'out') {
            foreach (self::BANK_FEE_WORDS as $word) {
                if (str_contains($description, $word)) {
                    return ['category' => 'bank_fee', 'account_id' => null, 'counterparty_id' => null, 'categorization_rule_id' => null];
                }
            }
        }

        return null;
    }

    /** @return list<array{invoice_id: int, amount: string}> */
    private function oldestFirstAllocations(Business $business, int $counterpartyId, string $kind, string $paidOn, string $amount): array
    {
        $remainingCents = $this->toCents($amount);
        $openCents = 0;
        $allocations = [];
        $invoices = $business->invoices()
            ->where('kind', $kind)
            ->where('document_type', 'invoice')
            ->where('counterparty_id', $counterpartyId)
            ->whereIn('status', ['issued', 'partially_paid'])
            ->whereDate('issued_on', '<=', $paidOn)
            ->with('payments')
            ->orderBy('issued_on')
            ->orderBy('id')
            ->get();

        foreach ($invoices as $invoice) {
            /** @var Invoice $invoice */
            $paidCents = $invoice->payments->sum(fn ($payment): int => $this->toCents((string) $payment->pivot->amount));
            $balanceCents = $this->toCents((string) $invoice->gross_amount) - $this->toCents((string) $invoice->credited_total) - $paidCents;
            if ($balanceCents <= 0) {
                continue;
            }
            $openCents += $balanceCents;

            if ($remainingCents > 0) {
                $allocatedCents = min($balanceCents, $remainingCents);
                $allocations[] = ['invoice_id' => (int) $invoice->getKey(), 'amount' => $this->fromCents($allocatedCents)];
                $remainingCents -= $allocatedCents;
            }
        }

        if ($remainingCents > 0) {
            $what = $kind === 'sale' ? 'customer invoices' : 'supplier bills';
            throw ValidationException::withMessages(['allocations' => ["Only {$this->fromCents($openCents)} is open on this party's {$what} up to this date, which is less than the bank amount. Issue or record the missing invoice first, or choose another category."]]);
        }

        return $allocations;
    }

    /**
     * @return array{int|null, int|null} the account and counterparty to book against
     */
    private function validatedTarget(Business $business, BankAccount $bankAccount, string $category, ?int $accountId, ?int $counterpartyId, string $amount): array
    {
        if (! isset(BankCategories::ALL[$category])) {
            throw ValidationException::withMessages(['category' => ['Choose a valid category.']]);
        }

        if (! BankCategories::fitsDirection($category, $amount)) {
            throw ValidationException::withMessages(['category' => [str_starts_with($amount, '-') ? 'This category is for money received, but this line is money paid out.' : 'This category is for money paid out, but this line is money received.']]);
        }

        if ($accountId !== null && (int) $accountId === (int) $bankAccount->account_id) {
            throw ValidationException::withMessages(['account_id' => ['Choose the account this money is for, not the bank account itself.']]);
        }

        return $this->target($business, $category, $accountId, $counterpartyId);
    }

    /**
     * Customer receipts and supplier payments need a party of the right type; the other categories
     * need an active account, and only transfers may use another bank account's ledger account.
     *
     * @return array{int|null, int|null}
     */
    private function target(Business $business, string $category, ?int $accountId, ?int $counterpartyId): array
    {
        if (BankCategories::ALL[$category]['needs'] === 'counterparty') {
            $type = $category === 'customer_receipt' ? 'customer' : 'supplier';
            if ($counterpartyId === null || ! $business->counterparties()->whereKey($counterpartyId)->whereIn('type', [$type, 'both'])->exists()) {
                throw ValidationException::withMessages(['counterparty_id' => [$type === 'customer' ? 'Choose the customer this money is from.' : 'Choose the supplier this money is for.']]);
            }

            return [null, (int) $counterpartyId];
        }

        if ($accountId === null || ! $business->accounts()->whereKey($accountId)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['account_id' => ['Choose the active account this money is for.']]);
        }

        if ($category !== 'transfer' && $business->bankAccounts()->where('account_id', $accountId)->exists()) {
            throw ValidationException::withMessages(['account_id' => ['A bank account\'s ledger account can only be used for a transfer between own accounts.']]);
        }

        return [(int) $accountId, null];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function validatedRule(Business $business, array $attributes, ?int $ignoreRuleId = null): array
    {
        $category = (string) $attributes['category'];
        $direction = (string) ($attributes['direction'] ?? 'any');
        $matchText = BankCategories::normalize((string) $attributes['match_text']);

        if (mb_strlen($matchText) < 3) {
            throw ValidationException::withMessages(['match_text' => ['The text to match must be at least 3 characters.']]);
        }

        $categoryDirection = BankCategories::ALL[$category]['direction'] ?? null;
        if ($categoryDirection === null) {
            throw ValidationException::withMessages(['category' => ['Choose a valid category.']]);
        }
        if ($categoryDirection !== 'any' && $direction !== $categoryDirection) {
            $direction = $categoryDirection;
        }

        [$accountId, $counterpartyId] = $this->target(
            $business,
            $category,
            isset($attributes['account_id']) ? (int) $attributes['account_id'] : null,
            isset($attributes['counterparty_id']) ? (int) $attributes['counterparty_id'] : null,
        );

        $duplicate = $business->bankCategorizationRules()->where('match_text', $matchText)->where('direction', $direction)
            ->when($ignoreRuleId !== null, fn ($query) => $query->whereKeyNot($ignoreRuleId))->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['match_text' => ['A rule for this text and direction already exists.']]);
        }

        return ['match_text' => $matchText, 'direction' => $direction, 'category' => $category, 'account_id' => $accountId, 'counterparty_id' => $counterpartyId, 'is_active' => (bool) ($attributes['is_active'] ?? true)];
    }

    private function openStatementLine(Business $business, int $bankAccountId, int $transactionId): BankTransaction
    {
        $bankAccount = $business->bankAccounts()->whereKey($bankAccountId)->firstOrFail();
        $transaction = $bankAccount->transactions()->whereKey($transactionId)->lockForUpdate()->firstOrFail();

        if ($transaction->source !== 'import' || $transaction->is_reconciled) {
            throw ValidationException::withMessages(['transaction' => ['Only bank statement lines that are not booked yet can be categorised.']]);
        }

        return $transaction->setRelation('bankAccount', $bankAccount);
    }

    private function loaded(BankTransaction $transaction): BankTransaction
    {
        return $transaction->load(['journalEntry', 'document', 'account', 'counterparty']);
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    private function audit(Business $business, User $user, string $action, BankCategorizationRule|BankTransaction $subject, array $old, array $new): void
    {
        $business->auditLogs()->create([
            'user_id' => $user->getKey(),
            'action' => $action,
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
            'old_values' => $old === [] ? null : $old,
            'new_values' => $new === [] ? null : $new,
        ]);
    }

    private function toCents(string $amount): int
    {
        $negative = str_starts_with($amount, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($amount, '+-'), 2), 2, '');
        $cents = ((int) $whole * 100) + (int) substr(str_pad($fraction, 2, '0'), 0, 2);

        return $negative ? -$cents : $cents;
    }

    private function fromCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return $sign.intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
