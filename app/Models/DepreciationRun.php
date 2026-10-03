<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['business_id', 'period_year', 'period_month', 'status', 'total', 'created_by'])]
class DepreciationRun extends Model
{
    protected function casts(): array
    {
        return ['total' => 'decimal:2', 'approved_at' => 'datetime'];
    }

    /** @return HasMany<DepreciationLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(DepreciationLine::class);
    }
}
