<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['business_id', 'fixed_asset_id', 'event_type', 'occurred_on', 'details', 'journal_entry_id', 'user_id'])]
class FixedAssetEvent extends Model
{
    protected function casts(): array
    {
        return ['occurred_on' => 'date', 'details' => 'array'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
