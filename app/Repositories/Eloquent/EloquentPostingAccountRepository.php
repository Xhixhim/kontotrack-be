<?php

namespace App\Repositories\Eloquent;

use App\Models\Business;
use App\Models\BusinessPostingAccount;
use App\Repositories\Contracts\PostingAccountRepository;
use Illuminate\Database\Eloquent\Collection;

class EloquentPostingAccountRepository implements PostingAccountRepository
{
    /** @return Collection<int, BusinessPostingAccount> */
    public function listForBusiness(Business $business): Collection
    {
        return $business->postingAccounts()->with('account')->get();
    }

    /** @param array<string, int|null> $accountIdsByPurpose */
    public function replaceForBusiness(Business $business, array $accountIdsByPurpose): void
    {
        foreach ($accountIdsByPurpose as $purpose => $accountId) {
            if ($accountId === null) {
                $business->postingAccounts()->where('purpose', $purpose)->delete();

                continue;
            }

            $business->postingAccounts()->updateOrCreate(['purpose' => $purpose], ['account_id' => $accountId]);
        }
    }
}
