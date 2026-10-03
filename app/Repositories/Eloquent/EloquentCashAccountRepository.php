<?php

namespace App\Repositories\Eloquent;

use App\Models\Business;
use App\Models\CashAccount;
use App\Repositories\Contracts\CashAccountRepository;
use Illuminate\Database\Eloquent\Collection;

class EloquentCashAccountRepository implements CashAccountRepository
{
    /** @return Collection<int, CashAccount> */
    public function listForBusiness(Business $business): Collection
    {
        return $business->cashAccounts()->with('account')->orderBy('name')->get();
    }

    /** @param array<string, mixed> $attributes */
    public function createForBusiness(Business $business, array $attributes): CashAccount
    {
        return $business->cashAccounts()->create($attributes)->load('account');
    }
}
