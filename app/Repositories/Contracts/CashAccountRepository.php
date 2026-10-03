<?php

namespace App\Repositories\Contracts;

use App\Models\Business;
use App\Models\CashAccount;
use Illuminate\Database\Eloquent\Collection;

interface CashAccountRepository
{
    /** @return Collection<int, CashAccount> */
    public function listForBusiness(Business $business): Collection;

    /** @param array<string, mixed> $attributes */
    public function createForBusiness(Business $business, array $attributes): CashAccount;
}
