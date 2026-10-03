<?php

namespace Tests\Feature\Api\V1;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\BusinessPostingAccount;
use App\Models\FixedAsset;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FixedAssetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-07-20 10:00:00');
    }

    public function test_registering_an_asset_records_its_acquisition_and_full_book_value(): void
    {
        [$user, $business] = $this->assetBusiness();

        $response = $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/fixed-assets", [
            'asset_code' => 'FA-001',
            'name' => 'Laptop',
            'category' => 'IT equipment',
            'location' => 'Zyra Prishtinë',
            'acquired_on' => '2026-01-15',
            'cost' => '1200.00',
            'depreciation_method' => 'straight_line',
            'useful_life_months' => 12,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.book_value', '1200.00')
            ->assertJsonPath('data.accumulated_depreciation', '0.00')
            ->assertJsonPath('data.events.0.event_type', 'acquired');
        $this->assertSame('fixed_asset.created', AuditLog::query()->where('subject_type', FixedAsset::class)->sole()->action);
    }

    public function test_rejects_invalid_asset_parameters_with_422(): void
    {
        [$user, $business] = $this->assetBusiness();
        FixedAsset::factory()->for($business)->create(['asset_code' => 'FA-001']);
        $valid = ['asset_code' => 'FA-002', 'name' => 'Van', 'category' => 'Vehicles', 'acquired_on' => '2026-01-01', 'cost' => '10000.00', 'depreciation_method' => 'straight_line', 'useful_life_months' => 60];

        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/fixed-assets", ['asset_code' => 'FA-001'] + $valid)
            ->assertUnprocessable()->assertJsonValidationErrors(['asset_code' => 'This asset code is already used in this business.']);
        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/fixed-assets", ['residual_value' => '10000.00'] + $valid)
            ->assertUnprocessable()->assertJsonValidationErrors(['residual_value' => 'The residual value must be lower than the cost.']);
        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/fixed-assets", ['useful_life_months' => null] + $valid)
            ->assertUnprocessable()->assertJsonValidationErrors(['useful_life_months' => 'Straight-line depreciation needs a useful life in months.']);
    }

    public function test_approved_depreciation_run_posts_expense_and_reduces_book_value(): void
    {
        [$user, $business] = $this->assetBusiness();
        $accounts = $this->mapAssetAccounts($business);
        $asset = FixedAsset::factory()->for($business)->create(['acquired_on' => '2026-01-15', 'cost' => '1200.00', 'useful_life_months' => 12]);
        FixedAsset::factory()->for($business)->create(['acquired_on' => '2026-02-10']);

        $runId = $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/depreciation-runs", ['period_year' => 2026, 'period_month' => 2])
            ->assertCreated()
            ->assertJsonCount(1, 'data.lines')
            ->assertJsonPath('data.total', '100.00')
            ->json('data.id');
        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/depreciation-runs/{$runId}/approve")->assertOk()->assertJsonPath('data.status', 'approved');

        $entry = JournalEntry::query()->with('lines')->sole();
        $this->assertSame('2026-02-28', $entry->entry_date->toDateString());
        $this->assertSame([
            [$accounts['depreciation_expense']->id, '100.00', '0.00'],
            [$accounts['accumulated_depreciation']->id, '0.00', '100.00'],
        ], $entry->lines->sortBy('id')->map(fn ($line): array => [$line->account_id, (string) $line->debit, (string) $line->credit])->values()->all());
        $this->actingAs($user)->getJson("/api/v1/businesses/{$business->id}/fixed-assets/{$asset->id}")
            ->assertOk()
            ->assertJsonPath('data.book_value', '1100.00')
            ->assertJsonPath('data.depreciation.0.period', '2026-02');
    }

    public function test_runs_must_be_created_in_month_order(): void
    {
        [$user, $business] = $this->assetBusiness();
        FixedAsset::factory()->for($business)->create(['acquired_on' => '2025-12-01']);
        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/depreciation-runs", ['period_year' => 2026, 'period_month' => 2])->assertCreated();

        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/depreciation-runs", ['period_year' => 2026, 'period_month' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['period_month' => 'Depreciation runs must follow the latest run (2026-02).']);
        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/depreciation-runs", ['period_year' => 2026, 'period_month' => 3])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['period_month' => 'Approve or delete the draft depreciation run first.']);
    }

    public function test_depreciation_stops_at_the_residual_value(): void
    {
        [$user, $business] = $this->assetBusiness();
        $this->mapAssetAccounts($business);
        FixedAsset::factory()->for($business)->create(['acquired_on' => '2026-01-01', 'cost' => '150.00', 'residual_value' => '50.00', 'useful_life_months' => 2]);

        foreach ([2, 3] as $month) {
            $runId = $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/depreciation-runs", ['period_year' => 2026, 'period_month' => $month])
                ->assertCreated()->assertJsonPath('data.total', '50.00')->json('data.id');
            $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/depreciation-runs/{$runId}/approve")->assertOk();
        }

        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/depreciation-runs", ['period_year' => 2026, 'period_month' => 4])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['period_month' => 'No asset needs depreciation for this month.']);
    }

    public function test_disposal_writes_off_the_book_value_and_removes_the_asset(): void
    {
        [$user, $business] = $this->assetBusiness();
        $accounts = $this->mapAssetAccounts($business);
        $asset = FixedAsset::factory()->for($business)->create(['acquired_on' => '2026-01-15', 'cost' => '1200.00', 'useful_life_months' => 12]);
        $runId = $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/depreciation-runs", ['period_year' => 2026, 'period_month' => 2])->json('data.id');
        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/depreciation-runs/{$runId}/approve")->assertOk();

        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/fixed-assets/{$asset->id}/dispose", [
            'disposed_on' => '2026-03-10',
            'disposal_type' => 'sold',
            'disposal_proceeds' => '900.00',
        ])->assertOk()
            ->assertJsonPath('data.status', 'disposed')
            ->assertJsonPath('data.book_value', '0.00')
            ->assertJsonPath('data.events.0.event_type', 'sold')
            ->assertJsonPath('data.events.0.details.book_value', '1100.00');

        $entry = JournalEntry::query()->with('lines')->latest('id')->first();
        $this->assertSame([
            [$accounts['accumulated_depreciation']->id, '100.00', '0.00'],
            [$accounts['asset_disposal_loss']->id, '1100.00', '0.00'],
            [$accounts['fixed_assets']->id, '0.00', '1200.00'],
        ], $entry->lines->sortBy('id')->map(fn ($line): array => [$line->account_id, (string) $line->debit, (string) $line->credit])->values()->all());
        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/depreciation-runs", ['period_year' => 2026, 'period_month' => 3])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['period_month' => 'No asset needs depreciation for this month.']);
    }

    public function test_disposal_needs_the_asset_posting_accounts(): void
    {
        [$user, $business] = $this->assetBusiness();
        $asset = FixedAsset::factory()->for($business)->create();

        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/fixed-assets/{$asset->id}/dispose", ['disposed_on' => '2026-03-10', 'disposal_type' => 'written_off'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('posting_accounts');

        $this->assertSame('active', $asset->refresh()->status);
    }

    public function test_transfer_records_the_old_and_new_location(): void
    {
        [$user, $business] = $this->assetBusiness();
        $asset = FixedAsset::factory()->for($business)->create(['location' => 'Depo Prizren']);

        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/fixed-assets/{$asset->id}/transfer", ['location' => 'Zyra Prishtinë', 'transferred_on' => '2026-05-01'])
            ->assertOk()
            ->assertJsonPath('data.location', 'Zyra Prishtinë')
            ->assertJsonPath('data.events.0.event_type', 'transferred')
            ->assertJsonPath('data.events.0.details.from', 'Depo Prizren');
    }

    public function test_auditor_cannot_register_assets_and_other_businesses_assets_are_hidden(): void
    {
        [, $business] = $this->assetBusiness();
        $auditor = User::factory()->create();
        $business->users()->attach($auditor, ['role' => 'auditor', 'status' => 'active']);
        $foreignAsset = FixedAsset::factory()->create();

        $this->actingAs($auditor)->postJson("/api/v1/businesses/{$business->id}/fixed-assets", [])->assertForbidden();
        $this->actingAs($auditor)->getJson("/api/v1/businesses/{$business->id}/fixed-assets/{$foreignAsset->id}")->assertNotFound();
    }

    /** @return array{User, Business} */
    private function assetBusiness(): array
    {
        $user = User::factory()->create();
        $business = Business::factory()->create();
        $business->users()->attach($user, ['role' => 'accountant', 'status' => 'active']);
        AccountingPeriod::factory()->for($business)->create();

        return [$user, $business];
    }

    /** @return array<string, Account> */
    private function mapAssetAccounts(Business $business): array
    {
        $types = ['fixed_assets' => 'asset', 'accumulated_depreciation' => 'asset', 'depreciation_expense' => 'expense', 'asset_disposal_loss' => 'expense'];
        $accounts = [];

        foreach ($types as $purpose => $type) {
            $accounts[$purpose] = Account::factory()->for($business)->ofType($type)->create();
            BusinessPostingAccount::factory()->create(['business_id' => $business->id, 'purpose' => $purpose, 'account_id' => $accounts[$purpose]->id]);
        }

        return $accounts;
    }
}
