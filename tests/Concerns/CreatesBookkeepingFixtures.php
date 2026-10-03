<?php

namespace Tests\Concerns;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Business;
use App\Models\BusinessPostingAccount;
use App\Models\ComplianceRuleSet;
use App\Models\JournalEntry;
use App\Models\User;

trait CreatesBookkeepingFixtures
{
    /**
     * A VAT-registered business with an accountant, an open period for the current year, and approved test VAT codes S18 and EX.
     *
     * @return array{User, Business}
     */
    private function bookkeepingBusiness(): array
    {
        $user = User::factory()->create();
        $business = Business::factory()->vatRegistered()->create();
        $business->users()->attach($user, ['role' => 'accountant', 'status' => 'active']);
        AccountingPeriod::factory()->for($business)->create();
        $ruleSet = ComplianceRuleSet::factory()->approvedVat()->create();
        $ruleSet->rules()->create([
            'rule_key' => 'vat_codes',
            'configuration' => ['codes' => [
                ['code' => 'S18', 'label' => 'Standard rate (test fixture)', 'rate_percent' => '18', 'treatment' => 'taxable'],
                ['code' => 'EX', 'label' => 'Exempt (test fixture)', 'rate_percent' => '0', 'treatment' => 'exempt'],
            ]],
        ]);

        return [$user, $business];
    }

    /**
     * @param  list<string>|null  $purposes
     * @return array<string, Account>
     */
    private function mapPostingAccounts(Business $business, ?array $purposes = null): array
    {
        $types = [
            'accounts_receivable' => 'asset',
            'accounts_payable' => 'liability',
            'sales_revenue' => 'revenue',
            'purchase_expense' => 'expense',
            'vat_output' => 'liability',
            'vat_input' => 'asset',
        ];
        $accounts = [];

        foreach ($purposes ?? array_keys($types) as $purpose) {
            $accounts[$purpose] = Account::factory()->for($business)->ofType($types[$purpose])->create();
            BusinessPostingAccount::factory()->create([
                'business_id' => $business->id,
                'purpose' => $purpose,
                'account_id' => $accounts[$purpose]->id,
            ]);
        }

        return $accounts;
    }

    /**
     * Create a draft invoice through the API with one line of 2 × 50.00 at the given VAT code.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function createDraft(User $user, Business $business, array $attributes, string $vatCode): int
    {
        return $this->actingAs($user)
            ->postJson("/api/v1/businesses/{$business->id}/invoices", $attributes + [
                'issued_on' => '2026-06-15',
                'lines' => [['description' => 'Consulting', 'quantity' => '2', 'unit_price' => '50.00', 'vat_code' => $vatCode]],
            ])
            ->assertCreated()
            ->json('data.id');
    }

    /** @return list<array{int, int|null, string, string}> */
    private function lineSummary(JournalEntry $entry): array
    {
        return $entry->lines
            ->sortBy('id')
            ->map(fn ($line): array => [$line->account_id, $line->counterparty_id, (string) $line->debit, (string) $line->credit])
            ->values()
            ->all();
    }
}
