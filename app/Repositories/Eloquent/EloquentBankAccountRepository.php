<?php

namespace App\Repositories\Eloquent;

use App\Models\BankAccount;
use App\Models\Business;
use App\Repositories\Contracts\BankAccountRepository;
use Illuminate\Database\Eloquent\Collection;

class EloquentBankAccountRepository implements BankAccountRepository
{
    /** @return Collection<int, BankAccount> */
    public function listForBusiness(Business $business): Collection
    {
        return $business->bankAccounts()
            ->with('account')
            ->withCount(['transactions as unreconciled_transactions_count' => fn ($query) => $query->where('is_reconciled', false)])
            ->orderBy('bank_name')
            ->get();
    }

    public function findForBusiness(Business $business, int $bankAccountId): BankAccount
    {
        return $business->bankAccounts()->whereKey($bankAccountId)->with('account')->firstOrFail();
    }

    /** @param array<string, mixed> $attributes */
    public function createForBusiness(Business $business, array $attributes): BankAccount
    {
        return $business->bankAccounts()->create($attributes)->load('account');
    }
}
