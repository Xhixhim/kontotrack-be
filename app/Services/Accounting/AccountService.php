<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\Business;
use App\Repositories\Contracts\AccountRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccountService
{
    public function __construct(private AccountRepository $accountRepository) {}

    /** @return Collection<int, Account> */
    public function listForBusiness(Business $business): Collection
    {
        return $this->accountRepository->listForBusiness($business);
    }

    /** @param array<string, mixed> $attributes */
    public function createForBusiness(Business $business, int $userId, array $attributes): Account
    {
        if ($business->accounts()->where('code', $attributes['code'])->exists()) {
            throw ValidationException::withMessages(['code' => ['This account code is already used in this business.']]);
        }

        if (isset($attributes['parent_id']) && ! $business->accounts()->whereKey($attributes['parent_id'])->exists()) {
            throw ValidationException::withMessages(['parent_id' => ['Choose a parent account from this business.']]);
        }

        $attributes['is_system'] = false;
        $attributes['is_active'] = true;

        return DB::transaction(function () use ($business, $userId, $attributes): Account {
            $account = $this->accountRepository->createForBusiness($business, $attributes);
            $business->auditLogs()->create([
                'user_id' => $userId,
                'action' => 'account.created',
                'subject_type' => Account::class,
                'subject_id' => $account->getKey(),
                'new_values' => $account->only(['code', 'name', 'account_type', 'normal_balance']),
            ]);

            return $account;
        });
    }
}
