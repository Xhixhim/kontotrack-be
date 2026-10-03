<?php

namespace App\Models;

use Database\Factories\CounterpartyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['business_id', 'type', 'name', 'nui', 'email', 'phone', 'address', 'municipality', 'country_code', 'status', 'metadata'])]
class Counterparty extends Model
{
    /** @use HasFactory<CounterpartyFactory> */
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** @return HasMany<Invoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
