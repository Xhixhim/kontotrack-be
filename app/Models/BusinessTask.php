<?php

namespace App\Models;

use Database\Factories\BusinessTaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['business_id', 'kind', 'obligation_type', 'period_label', 'title', 'notes', 'due_on', 'status', 'assigned_to', 'compliance_rule_set_id', 'created_by'])]
class BusinessTask extends Model
{
    /** @use HasFactory<BusinessTaskFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['due_on' => 'date', 'completed_on' => 'date', 'amount' => 'decimal:2'];
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
