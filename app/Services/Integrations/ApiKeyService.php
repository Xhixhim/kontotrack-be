<?php

namespace App\Services\Integrations;

use App\Models\Business;
use App\Models\BusinessApiKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ApiKeyService
{
    /** @return Collection<int, BusinessApiKey> */
    public function listForBusiness(Business $business): Collection
    {
        return BusinessApiKey::query()->where('business_id', $business->getKey())->with('creator:id,name')->latest('id')->get();
    }

    /**
     * Create a key. The token is returned once and never stored; only its hash is kept.
     *
     * @param  array{name: string, abilities: list<string>}  $attributes
     * @return array{key: BusinessApiKey, token: string}
     */
    public function create(Business $business, User $user, array $attributes): array
    {
        return DB::transaction(function () use ($business, $user, $attributes): array {
            $secret = Str::random(48);
            $key = BusinessApiKey::query()->create([
                'business_id' => $business->getKey(),
                'created_by' => $user->getKey(),
                'name' => $attributes['name'],
                'token_hash' => hash('sha256', $secret),
                'abilities' => array_values(array_intersect(BusinessApiKey::ABILITIES, $attributes['abilities'])),
            ]);
            $business->auditLogs()->create([
                'user_id' => $user->getKey(),
                'action' => 'api_key.created',
                'subject_type' => BusinessApiKey::class,
                'subject_id' => $key->getKey(),
                'new_values' => $key->only(['name', 'abilities']),
            ]);

            return ['key' => $key->load('creator:id,name'), 'token' => "kt_{$key->getKey()}_{$secret}"];
        });
    }

    public function revoke(Business $business, User $user, int $keyId): void
    {
        DB::transaction(function () use ($business, $user, $keyId): void {
            $key = BusinessApiKey::query()->where('business_id', $business->getKey())->whereKey($keyId)->whereNull('revoked_at')->lockForUpdate()->firstOrFail();
            $key->update(['revoked_at' => now()]);
            $business->auditLogs()->create([
                'user_id' => $user->getKey(),
                'action' => 'api_key.revoked',
                'subject_type' => BusinessApiKey::class,
                'subject_id' => $key->getKey(),
                'old_values' => $key->only(['name']),
            ]);
        });
    }

    /**
     * Find the usable key behind a bearer token of the form kt_{id}_{secret}.
     */
    public function resolve(?string $token): ?BusinessApiKey
    {
        if ($token === null || preg_match('/^kt_(\d+)_([A-Za-z0-9]{48})$/', $token, $parts) !== 1) {
            return null;
        }

        $key = BusinessApiKey::query()->whereKey((int) $parts[1])->whereNull('revoked_at')->with(['business', 'creator'])->first();

        if ($key === null || ! hash_equals($key->token_hash, hash('sha256', $parts[2]))) {
            return null;
        }

        return $key;
    }
}
