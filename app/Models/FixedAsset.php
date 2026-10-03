<?php

namespace App\Models;

use Database\Factories\FixedAssetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['business_id', 'asset_code', 'name', 'category', 'location', 'acquired_on', 'cost', 'residual_value', 'opening_accumulated_depreciation', 'depreciation_method', 'useful_life_months', 'annual_rate_percent', 'status', 'document_id', 'created_by'])]
class FixedAsset extends Model
{
    /** @use HasFactory<FixedAssetFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'acquired_on' => 'date',
            'disposed_on' => 'date',
            'cost' => 'decimal:2',
            'residual_value' => 'decimal:2',
            'opening_accumulated_depreciation' => 'decimal:2',
            'annual_rate_percent' => 'decimal:4',
            'disposal_proceeds' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** @return HasMany<DepreciationLine, $this> */
    public function depreciationLines(): HasMany
    {
        return $this->hasMany(DepreciationLine::class);
    }

    /** @return HasMany<FixedAssetEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(FixedAssetEvent::class);
    }
}
