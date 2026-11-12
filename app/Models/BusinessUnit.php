<?php

namespace App\Models;

use Database\Factories\BusinessUnitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A branch, shop or department of a business. Invoices and journal entries can be tagged with one,
 * so income and costs can be reported per unit.
 */
#[Fillable(['business_id', 'name', 'code', 'is_active'])]
class BusinessUnit extends Model
{
    /** @use HasFactory<BusinessUnitFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
