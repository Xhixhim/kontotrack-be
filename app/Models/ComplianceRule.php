<?php

namespace App\Models;

use Database\Factories\ComplianceRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['compliance_rule_set_id', 'rule_key', 'configuration', 'description'])]
class ComplianceRule extends Model
{
    /** @use HasFactory<ComplianceRuleFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['configuration' => 'array'];
    }

    /** @return BelongsTo<ComplianceRuleSet, $this> */
    public function ruleSet(): BelongsTo
    {
        return $this->belongsTo(ComplianceRuleSet::class, 'compliance_rule_set_id');
    }
}
