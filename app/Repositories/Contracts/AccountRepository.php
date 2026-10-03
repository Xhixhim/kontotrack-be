<?php

namespace App\Repositories\Contracts;

use App\Models\Account;
use App\Models\Business;
use Illuminate\Database\Eloquent\Collection;

interface AccountRepository
{
    /** @return Collection<int, Account> */
    public function listForBusiness(Business $business): Collection;

    /** @param array<string, mixed> $attributes */
    public function createForBusiness(Business $business, array $attributes): Account;
}
