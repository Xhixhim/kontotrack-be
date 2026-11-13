<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A key another system (POS, web shop) uses to send sales to one business and read its products.
 */
#[Fillable(['business_id', 'created_by', 'name', 'token_hash', 'abilities', 'last_used_at', 'revoked_at'])]
#[Hidden(['token_hash'])]
class BusinessApiKey extends Model
{
    /** @var list<string> */
    public const ABILITIES = ['read', 'customers.write', 'sales.write'];

    protected function casts(): array
    {
        return ['abilities' => 'array', 'last_used_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function allows(string $ability): bool
    {
        return in_array($ability, $this->abilities ?? [], true);
    }
}
