<?php

namespace App\Models;

use Database\Factories\ComplianceRuleSetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['jurisdiction', 'domain', 'version', 'valid_from', 'valid_to', 'source_title', 'source_url', 'created_by', 'approval_status', 'approved_by', 'approved_at'])]
class ComplianceRuleSet extends Model
{
    /** @use HasFactory<ComplianceRuleSetFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['valid_from' => 'date', 'valid_to' => 'date', 'approved_at' => 'datetime'];
    }

    /** @return HasMany<ComplianceRule, $this> */
    public function rules(): HasMany
    {
        return $this->hasMany(ComplianceRule::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
