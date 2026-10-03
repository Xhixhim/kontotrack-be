<?php

namespace App\Models;

use Database\Factories\InventoryItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['business_id', 'supplier_counterparty_id', 'sku', 'barcode', 'name', 'description', 'unit', 'purchase_price', 'sale_price', 'minimum_quantity', 'is_active'])]
class InventoryItem extends Model
{
    /** @use HasFactory<InventoryItemFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'purchase_price' => 'decimal:4',
            'sale_price' => 'decimal:4',
            'minimum_quantity' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** @return BelongsTo<Counterparty, $this> */
    public function preferredSupplier(): BelongsTo
    {
        return $this->belongsTo(Counterparty::class, 'supplier_counterparty_id');
    }

    /** @return HasMany<StockMovement, $this> */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }
}
