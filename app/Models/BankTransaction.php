<?php

namespace App\Models;

use Database\Factories\BankTransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['business_id', 'bank_account_id', 'source', 'journal_entry_id', 'document_id', 'transaction_date', 'external_id', 'description', 'amount', 'balance_after', 'currency', 'is_reconciled', 'reconciled_at', 'category', 'account_id', 'counterparty_id', 'categorization_rule_id', 'categorized_by', 'categorized_at'])]
class BankTransaction extends Model
{
    /** @use HasFactory<BankTransactionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['transaction_date' => 'date', 'amount' => 'decimal:2', 'balance_after' => 'decimal:2', 'is_reconciled' => 'boolean', 'reconciled_at' => 'datetime', 'categorized_at' => 'datetime'];
    }

    /** @return BelongsTo<BankAccount, $this> */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<Counterparty, $this> */
    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Counterparty::class);
    }

    /** @return BelongsTo<BankCategorizationRule, $this> */
    public function categorizationRule(): BelongsTo
    {
        return $this->belongsTo(BankCategorizationRule::class);
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
