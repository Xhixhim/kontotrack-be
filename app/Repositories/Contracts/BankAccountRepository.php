<?php

namespace App\Repositories\Contracts;

use App\Models\BankAccount;
use App\Models\Business;
use Illuminate\Database\Eloquent\Collection;

interface BankAccountRepository
{
    /** @return Collection<int, BankAccount> */
    public function listForBusiness(Business $business): Collection;

    public function findForBusiness(Business $business, int $bankAccountId): BankAccount;

    /** @param array<string, mixed> $attributes */
    public function createForBusiness(Business $business, array $attributes): BankAccount;
}
