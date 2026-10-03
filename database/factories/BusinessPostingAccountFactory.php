<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\BusinessPostingAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BusinessPostingAccount>
 */
class BusinessPostingAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $account = Account::factory()->create();

        return [
            'business_id' => $account->business_id,
            'purpose' => 'accounts_receivable',
            'account_id' => $account->getKey(),
        ];
    }
}
