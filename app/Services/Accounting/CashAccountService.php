<?php

namespace App\Services\Accounting;

use App\Models\Business;
use App\Models\CashAccount;
use App\Repositories\Contracts\CashAccountRepository;
use App\Repositories\Contracts\JournalEntryRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashAccountService
{
    public function __construct(
        private CashAccountRepository $cashAccountRepository,
        private JournalEntryRepository $journalEntryRepository,
    ) {}

    /** @return Collection<int, CashAccount> */
    public function listForBusiness(Business $business): Collection
    {
        $cashAccounts = $this->cashAccountRepository->listForBusiness($business);
        $balances = $this->journalEntryRepository->postedBalancesForAccounts(
            $business,
            $cashAccounts->pluck('account_id')->filter()->unique()->map(fn ($id): int => (int) $id)->values()->all(),
        );

        foreach ($cashAccounts as $cashAccount) {
            $cashAccount->setAttribute('balance', $balances[$cashAccount->account_id] ?? '0.00');
        }

        return $cashAccounts;
    }

    /** @param array{name: string, account_id: int} $attributes */
    public function createForBusiness(Business $business, int $userId, array $attributes): CashAccount
    {
        return DB::transaction(function () use ($business, $userId, $attributes): CashAccount {
            $account = $business->accounts()->whereKey($attributes['account_id'])->lockForUpdate()->first();

            if ($account === null || ! $account->is_active || $account->account_type !== 'asset') {
                throw ValidationException::withMessages(['account_id' => ['Choose an active asset account from this business.']]);
            }

            if ($business->cashAccounts()->where('account_id', $account->getKey())->exists() || $business->bankAccounts()->where('account_id', $account->getKey())->exists()) {
                throw ValidationException::withMessages(['account_id' => ['This ledger account is already mapped to a cash or bank account.']]);
            }

            $cashAccount = $this->cashAccountRepository->createForBusiness($business, [
                ...$attributes,
                'currency' => $business->currency,
                'is_active' => true,
            ]);
            $business->auditLogs()->create([
                'user_id' => $userId,
                'action' => 'cash_account.created',
                'subject_type' => CashAccount::class,
                'subject_id' => $cashAccount->getKey(),
                'new_values' => $cashAccount->only(['name', 'account_id', 'currency']),
            ]);

            return $cashAccount;
        });
    }
}
