<?php

namespace App\Services\Accounting;

use App\Models\BankAccount;
use App\Models\Business;
use App\Repositories\Contracts\BankAccountRepository;
use App\Repositories\Contracts\JournalEntryRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BankAccountService
{
    public function __construct(
        private BankAccountRepository $bankAccountRepository,
        private JournalEntryRepository $journalEntryRepository,
    ) {}

    /** @return Collection<int, BankAccount> */
    public function listForBusiness(Business $business): Collection
    {
        $bankAccounts = $this->bankAccountRepository->listForBusiness($business);
        $balances = $this->journalEntryRepository->postedBalancesForAccounts(
            $business,
            $bankAccounts->pluck('account_id')->filter()->unique()->map(fn ($id): int => (int) $id)->values()->all(),
        );

        foreach ($bankAccounts as $bankAccount) {
            $bankAccount->setAttribute('balance', $balances[$bankAccount->account_id] ?? '0.00');
        }

        return $bankAccounts;
    }

    /** @param array{bank_name: string, account_name?: string|null, iban?: string|null, account_id: int} $attributes */
    public function createForBusiness(Business $business, int $userId, array $attributes): BankAccount
    {
        return DB::transaction(function () use ($business, $userId, $attributes): BankAccount {
            $account = $business->accounts()->whereKey($attributes['account_id'])->lockForUpdate()->first();

            if ($account === null || ! $account->is_active || $account->account_type !== 'asset') {
                throw ValidationException::withMessages(['account_id' => ['Choose an active asset account from this business.']]);
            }

            if ($business->cashAccounts()->where('account_id', $account->getKey())->exists() || $business->bankAccounts()->where('account_id', $account->getKey())->exists()) {
                throw ValidationException::withMessages(['account_id' => ['This ledger account is already mapped to a cash or bank account.']]);
            }

            $bankAccount = $this->bankAccountRepository->createForBusiness($business, [
                ...$attributes,
                'currency' => $business->currency,
                'is_active' => true,
            ]);
            $business->auditLogs()->create([
                'user_id' => $userId,
                'action' => 'bank_account.created',
                'subject_type' => BankAccount::class,
                'subject_id' => $bankAccount->getKey(),
                'new_values' => $bankAccount->only(['bank_name', 'account_name', 'iban', 'account_id', 'currency']),
            ]);

            return $bankAccount;
        });
    }
}
