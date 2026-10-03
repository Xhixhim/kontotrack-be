<?php

namespace App\Repositories\Eloquent;

use App\Models\Account;
use App\Models\Business;
use App\Repositories\Contracts\AccountRepository;
use Illuminate\Database\Eloquent\Collection;

class EloquentAccountRepository implements AccountRepository
{
    /** @return Collection<int, Account> */
    public function listForBusiness(Business $business): Collection
    {
        return $business->accounts()->where('is_active', true)->orderBy('code')->get();
    }

    /** @param array<string, mixed> $attributes */
    public function createForBusiness(Business $business, array $attributes): Account
    {
        return $business->accounts()->create($attributes);
    }
}
