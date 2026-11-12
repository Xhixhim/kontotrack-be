<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\Business;
use App\Models\BusinessPostingAccount;
use App\Models\User;
use App\Repositories\Contracts\PostingAccountRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PostingAccountService
{
    /**
     * Ledger roles used when the system generates journal entries, with the account types allowed for each role.
     *
     * @var array<string, array{label: string, account_types: list<string>}>
     */
    public const PURPOSES = [
        'accounts_receivable' => ['label' => 'Customer receivables', 'account_types' => ['asset']],
        'accounts_payable' => ['label' => 'Supplier payables', 'account_types' => ['liability']],
        'sales_revenue' => ['label' => 'Sales revenue', 'account_types' => ['revenue']],
        'purchase_expense' => ['label' => 'Purchases and expenses', 'account_types' => ['expense', 'asset']],
        'vat_output' => ['label' => 'Output VAT payable', 'account_types' => ['liability']],
        'vat_input' => ['label' => 'Input VAT receivable', 'account_types' => ['asset']],
        'salary_expense' => ['label' => 'Gross salary expense', 'account_types' => ['expense']],
        'employer_contribution_expense' => ['label' => 'Employer pension contribution expense', 'account_types' => ['expense']],
        'net_salaries_payable' => ['label' => 'Net salaries payable', 'account_types' => ['liability']],
        'pension_contributions_payable' => ['label' => 'Pension contributions payable', 'account_types' => ['liability']],
        'income_tax_payable' => ['label' => 'Wage income tax payable', 'account_types' => ['liability']],
        'fixed_assets' => ['label' => 'Fixed assets at cost', 'account_types' => ['asset']],
        'accumulated_depreciation' => ['label' => 'Accumulated depreciation', 'account_types' => ['asset']],
        'depreciation_expense' => ['label' => 'Depreciation expense', 'account_types' => ['expense']],
        'asset_disposal_loss' => ['label' => 'Loss on asset disposal', 'account_types' => ['expense']],
        'retained_earnings' => ['label' => 'Retained earnings', 'account_types' => ['equity']],
    ];

    public function __construct(private PostingAccountRepository $postingAccountRepository) {}

    /** @return list<array{purpose: string, label: string, account_types: list<string>, account: Account|null}> */
    public function listForBusiness(Business $business): array
    {
        $mapped = $this->postingAccountRepository->listForBusiness($business)->keyBy('purpose');

        return collect(self::PURPOSES)
            ->map(fn (array $definition, string $purpose): array => [
                'purpose' => $purpose,
                'label' => $definition['label'],
                'account_types' => $definition['account_types'],
                'account' => $mapped->get($purpose)?->account,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, int|null>  $accountIdsByPurpose
     * @return list<array{purpose: string, label: string, account_types: list<string>, account: Account|null}>
     */
    public function updateForBusiness(Business $business, User $user, array $accountIdsByPurpose): array
    {
        DB::transaction(function () use ($business, $user, $accountIdsByPurpose): void {
            $accountIds = array_values(array_unique(array_filter($accountIdsByPurpose)));
            $accounts = $business->accounts()->whereIn('id', $accountIds)->where('is_active', true)->get()->keyBy('id');

            foreach ($accountIdsByPurpose as $purpose => $accountId) {
                if ($accountId === null) {
                    continue;
                }

                $account = $accounts->get($accountId);

                if ($account === null) {
                    throw ValidationException::withMessages(["accounts.{$purpose}" => ['Choose an active account from this business.']]);
                }

                if (! in_array($account->account_type, self::PURPOSES[$purpose]['account_types'], true)) {
                    throw ValidationException::withMessages(["accounts.{$purpose}" => ['This account type cannot be used for '.strtolower(self::PURPOSES[$purpose]['label']).'.']]);
                }
            }

            $oldValues = $business->postingAccounts()->pluck('account_id', 'purpose')->all();
            $this->postingAccountRepository->replaceForBusiness($business, $accountIdsByPurpose);
            $newValues = $business->postingAccounts()->pluck('account_id', 'purpose')->all();

            if ($oldValues !== $newValues) {
                $business->auditLogs()->create([
                    'user_id' => $user->getKey(),
                    'action' => 'posting_accounts.updated',
                    'subject_type' => BusinessPostingAccount::class,
                    'old_values' => $oldValues,
                    'new_values' => $newValues,
                ]);
            }
        });

        return $this->listForBusiness($business);
    }

    /**
     * Resolve the mapped accounts for the given purposes, failing with a clear message for any that are missing or inactive.
     *
     * @param  list<string>  $purposes
     * @return array<string, Account>
     */
    public function requireAccounts(Business $business, array $purposes): array
    {
        $mapped = $business->postingAccounts()->whereIn('purpose', $purposes)->with('account')->get()->keyBy('purpose');
        $resolved = [];
        $missing = [];

        foreach ($purposes as $purpose) {
            $account = $mapped->get($purpose)?->account;

            if ($account === null || ! $account->is_active) {
                $missing[] = strtolower(self::PURPOSES[$purpose]['label']);

                continue;
            }

            $resolved[$purpose] = $account;
        }

        if ($missing !== []) {
            throw ValidationException::withMessages(['posting_accounts' => ['Map active ledger accounts for '.implode(', ', $missing).' before posting automatically.']]);
        }

        return $resolved;
    }
}
