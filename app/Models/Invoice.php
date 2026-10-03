<?php

namespace App\Models;

use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['business_id', 'counterparty_id', 'document_id', 'journal_entry_id', 'compliance_rule_set_id', 'kind', 'document_type', 'credited_invoice_id', 'invoice_number', 'issued_on', 'due_on', 'payment_method', 'status', 'currency', 'net_amount', 'tax_amount', 'gross_amount', 'tax_snapshot', 'notes', 'created_by', 'issued_by', 'issued_at'])]
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'issued_on' => 'date',
            'due_on' => 'date',
            'issued_at' => 'datetime',
            'cancelled_on' => 'date',
            'cancelled_at' => 'datetime',
            'net_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'gross_amount' => 'decimal:2',
            'credited_total' => 'decimal:2',
            'tax_snapshot' => 'array',
        ];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function creditedInvoice(): BelongsTo
    {
        return $this->belongsTo(self::class, 'credited_invoice_id');
    }

    /** @return HasMany<Invoice, $this> */
    public function creditNotes(): HasMany
    {
        return $this->hasMany(self::class, 'credited_invoice_id');
    }

    public function isCreditNote(): bool
    {
        return $this->document_type === 'credit_note';
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** @return BelongsTo<Counterparty, $this> */
    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Counterparty::class);
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /** @return HasMany<InvoiceLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('position');
    }

    /** @return BelongsToMany<Payment, $this> */
    public function payments(): BelongsToMany
    {
        return $this->belongsToMany(Payment::class)->withPivot(['business_id', 'amount'])->withTimestamps();
    }
}
