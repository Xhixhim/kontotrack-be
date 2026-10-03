<?php

namespace App\Repositories\Contracts;

use App\Models\Business;
use App\Models\BusinessPostingAccount;
use Illuminate\Database\Eloquent\Collection;

interface PostingAccountRepository
{
    /** @return Collection<int, BusinessPostingAccount> */
    public function listForBusiness(Business $business): Collection;

    /** @param array<string, int|null> $accountIdsByPurpose */
    public function replaceForBusiness(Business $business, array $accountIdsByPurpose): void;
}
