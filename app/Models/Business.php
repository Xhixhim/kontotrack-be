<?php

namespace App\Models;

use Database\Factories\BusinessFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['legal_name', 'trade_name', 'nui', 'legal_form', 'owner_name', 'vat_status', 'municipality', 'address', 'email', 'phone', 'activity_code', 'registered_at', 'currency', 'status', 'metadata'])]
class Business extends Model
{
    /** @use HasFactory<BusinessFactory> */
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return ['registered_at' => 'date', 'metadata' => 'array'];
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot(['role', 'status', 'invited_by', 'invited_at', 'accepted_at'])->withTimestamps();
    }

    /** @return HasMany<AccountingPeriod, $this> */
    public function accountingPeriods(): HasMany
    {
        return $this->hasMany(AccountingPeriod::class);
    }

    /** @return HasMany<Account, $this> */
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    /** @return HasMany<Counterparty, $this> */
    public function counterparties(): HasMany
    {
        return $this->hasMany(Counterparty::class);
    }

    /** @return HasMany<InventoryItem, $this> */
    public function inventoryItems(): HasMany
    {
        return $this->hasMany(InventoryItem::class);
    }

    /** @return HasMany<Warehouse, $this> */
    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class);
    }

    /** @return HasMany<StockMovement, $this> */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /** @return HasMany<Invoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /** @return HasMany<CashAccount, $this> */
    public function cashAccounts(): HasMany
    {
        return $this->hasMany(CashAccount::class);
    }

    /** @return HasMany<BankAccount, $this> */
    public function bankAccounts(): HasMany
    {
        return $this->hasMany(BankAccount::class);
    }

    /** @return HasMany<BankCategorizationRule, $this> */
    public function bankCategorizationRules(): HasMany
    {
        return $this->hasMany(BankCategorizationRule::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return HasMany<JournalEntry, $this> */
    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class);
    }

    /** @return HasMany<Document, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /** @return HasMany<Conversation, $this> */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /** @return HasMany<DocumentRequest, $this> */
    public function documentRequests(): HasMany
    {
        return $this->hasMany(DocumentRequest::class);
    }

    /** @return HasMany<BusinessTask, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(BusinessTask::class);
    }

    /** @return HasMany<FixedAsset, $this> */
    public function fixedAssets(): HasMany
    {
        return $this->hasMany(FixedAsset::class);
    }

    /** @return HasMany<DepreciationRun, $this> */
    public function depreciationRuns(): HasMany
    {
        return $this->hasMany(DepreciationRun::class);
    }

    /** @return HasMany<Employee, $this> */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    /** @return HasMany<PayrollRun, $this> */
    public function payrollRuns(): HasMany
    {
        return $this->hasMany(PayrollRun::class);
    }

    /** @return HasMany<BusinessPostingAccount, $this> */
    public function postingAccounts(): HasMany
    {
        return $this->hasMany(BusinessPostingAccount::class);
    }

    /** @return HasMany<AuditLog, $this> */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }
}
