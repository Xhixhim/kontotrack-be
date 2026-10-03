<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['business_id', 'depreciation_run_id', 'fixed_asset_id', 'opening_book_value', 'amount', 'closing_book_value'])]
class DepreciationLine extends Model
{
    protected function casts(): array
    {
        return ['opening_book_value' => 'decimal:2', 'amount' => 'decimal:2', 'closing_book_value' => 'decimal:2'];
    }

    /** @return BelongsTo<DepreciationRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(DepreciationRun::class, 'depreciation_run_id');
    }

    /** @return BelongsTo<FixedAsset, $this> */
    public function fixedAsset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class);
    }
}
