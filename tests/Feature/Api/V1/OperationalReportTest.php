<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Counterparty;
use App\Models\Document;
use App\Models\Employee;
use App\Models\FixedAsset;
use App\Models\InventoryItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesBookkeepingFixtures;
use Tests\TestCase;

class OperationalReportTest extends TestCase
{
    use CreatesBookkeepingFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-06-20 10:00:00');
    }

    public function test_turnover_groups_issued_sales_and_purchases_by_month_and_by_counterparty(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $this->mapPostingAccounts($business);
        $drini = Counterparty::factory()->for($business)->customer()->create(['name' => 'Drini']);
        $ereniku = Counterparty::factory()->for($business)->customer()->create(['name' => 'Ereniku']);
        $supplier = Counterparty::factory()->for($business)->supplier()->create(['name' => 'Furnitori']);
        $this->issue($user, $business, ['kind' => 'sale', 'counterparty_id' => $drini->id, 'issued_on' => '2026-05-10']);
        $this->issue($user, $business, ['kind' => 'sale', 'counterparty_id' => $ereniku->id, 'issued_on' => '2026-06-05']);
        $bill = Document::factory()->for($business)->invoice()->create();
        $this->issue($user, $business, ['kind' => 'purchase', 'counterparty_id' => $supplier->id, 'invoice_number' => 'F-1', 'issued_on' => '2026-06-08', 'document_id' => $bill->id]);
        $this->createDraft($user, $business, ['kind' => 'sale', 'counterparty_id' => $drini->id], 'S18');

        $this->actingAs($user)->getJson("/api/v1/businesses/{$business->id}/reports/financial?type=turnover&from=2026-05-01&to=2026-06-30")
            ->assertOk()
            ->assertJsonCount(2, 'data.rows')
            ->assertJsonPath('data.rows.0.label', '05.2026')
            ->assertJsonPath('data.rows.0.sales_gross', '118.00')
            ->assertJsonPath('data.rows.1.sales_net', '100.00')
            ->assertJsonPath('data.rows.1.purchases_gross', '118.00')
            ->assertJsonPath('data.totals.sales_gross', '236.00')
            ->assertJsonPath('data.totals.purchases_tax', '18.00')
            ->assertJsonPath('data.table.title', 'Qarkullimi · Turnover');

        $this->actingAs($user)->getJson("/api/v1/businesses/{$business->id}/reports/financial?type=turnover&group_by=counterparty&from=2026-05-01&to=2026-06-30")
            ->assertOk()
            ->assertJsonPath('data.rows.0.label', 'Drini')
            ->assertJsonPath('data.rows.2.label', 'Furnitori');

        $this->actingAs($user)->getJson("/api/v1/businesses/{$business->id}/reports/financial?type=turnover&counterparty_id={$ereniku->id}&from=2026-05-01&to=2026-06-30")
            ->assertOk()
            ->assertJsonCount(1, 'data.rows')
            ->assertJsonPath('data.counterparty.name', 'Ereniku')
            ->assertJsonPath('data.totals.sales_gross', '118.00');
    }

    public function test_rejects_a_counterparty_of_another_business_with_422(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $foreign = Counterparty::factory()->customer()->create();

        $this->actingAs($user)->getJson("/api/v1/businesses/{$business->id}/reports/financial?type=turnover&counterparty_id={$foreign->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('counterparty_id');
        $this->actingAs($user)->getJson("/api/v1/businesses/{$business->id}/reports/financial?type=receivable_aging&counterparty_id={$foreign->id}")
            ->assertUnprocessable();
    }

    public function test_receivable_aging_can_be_limited_to_one_customer(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $this->mapPostingAccounts($business);
        $drini = Counterparty::factory()->for($business)->customer()->create(['name' => 'Drini']);
        $ereniku = Counterparty::factory()->for($business)->customer()->create(['name' => 'Ereniku']);
        $this->issue($user, $business, ['kind' => 'sale', 'counterparty_id' => $drini->id]);
        $this->issue($user, $business, ['kind' => 'sale', 'counterparty_id' => $ereniku->id]);
        $this->issue($user, $business, ['kind' => 'sale', 'counterparty_id' => $ereniku->id]);

        $this->actingAs($user)->getJson("/api/v1/businesses/{$business->id}/reports/financial?type=receivable_aging&counterparty_id={$ereniku->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.rows')
            ->assertJsonPath('data.rows.0.counterparty_name', 'Ereniku')
            ->assertJsonPath('data.totals.total', '236.00')
            ->assertJsonPath('data.totals.invoice_count', 2);
    }

    public function test_stock_report_shows_opening_movement_and_value_per_item(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $main = Warehouse::factory()->for($business)->create(['name' => 'Depo kryesore']);
        $shop = Warehouse::factory()->for($business)->create(['name' => 'Dyqani']);
        $item = InventoryItem::factory()->for($business)->create(['sku' => 'KAFE-1', 'name' => 'Kafe 1kg', 'purchase_price' => '8.5000', 'minimum_quantity' => '10.0000']);
        InventoryItem::factory()->for($business)->create(['name' => 'Sheqer', 'purchase_price' => '1.0000']);
        $move = fn (Warehouse $warehouse, string $delta, string $date) => StockMovement::factory()->for($business)->create(['inventory_item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'quantity_delta' => $delta, 'occurred_on' => $date]);
        $move($main, '20.0000', '2026-05-20');
        $move($main, '5.5000', '2026-06-03');
        $move($main, '-18.0000', '2026-06-10');
        $move($shop, '4.0000', '2026-06-12');
        $move($main, '100.0000', '2026-07-01');

        $this->actingAs($user)->getJson("/api/v1/businesses/{$business->id}/reports/financial?type=stock&from=2026-06-01&to=2026-06-30")
            ->assertOk()
            ->assertJsonCount(2, 'data.rows')
            ->assertJsonPath('data.rows.0.name', 'Kafe 1kg')
            ->assertJsonPath('data.rows.0.opening_quantity', '20')
            ->assertJsonPath('data.rows.0.quantity_in', '9.5')
            ->assertJsonPath('data.rows.0.quantity_out', '18')
            ->assertJsonPath('data.rows.0.closing_quantity', '11.5')
            ->assertJsonPath('data.rows.0.below_minimum', false)
            ->assertJsonPath('data.rows.0.value', '97.75')
            ->assertJsonPath('data.rows.1.closing_quantity', '0')
            ->assertJsonPath('data.totals.value', '97.75');

        $this->actingAs($user)->getJson("/api/v1/businesses/{$business->id}/reports/financial?type=stock&warehouse_id={$main->id}&from=2026-06-01&to=2026-06-30")
            ->assertOk()
            ->assertJsonPath('data.warehouse.name', 'Depo kryesore')
            ->assertJsonPath('data.rows.0.closing_quantity', '7.5');
    }

    public function test_payroll_report_totals_approved_runs_per_employee(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $this->payrollRun($business, 2026, 5, 'approved', [['Arta Krasniqi', '800.00', '40.00', '40.00', '60.80', '699.20'], ['Besnik Gashi', '500.00', '25.00', '25.00', '23.00', '452.00']]);
        $this->payrollRun($business, 2026, 6, 'approved', [['Arta Krasniqi', '800.00', '40.00', '40.00', '60.80', '699.20']]);
        $this->payrollRun($business, 2026, 7, 'draft', [['Arta Krasniqi', '900.00', '45.00', '45.00', '70.00', '785.00']]);

        $this->actingAs($user)->getJson("/api/v1/businesses/{$business->id}/reports/financial?type=payroll&from=2026-05-01&to=2026-07-31")
            ->assertOk()
            ->assertJsonPath('data.months', ['2026-05', '2026-06'])
            ->assertJsonPath('data.rows.0.employee_name', 'Arta Krasniqi')
            ->assertJsonPath('data.rows.0.months', 2)
            ->assertJsonPath('data.rows.0.gross_salary', '1600.00')
            ->assertJsonPath('data.rows.0.net_salary', '1398.40')
            ->assertJsonPath('data.totals.gross_salary', '2100.00')
            ->assertJsonPath('data.totals.income_tax', '144.60');
    }

    public function test_payroll_report_is_hidden_from_members_who_may_not_see_payroll(): void
    {
        [, $business] = $this->bookkeepingBusiness();
        $assistant = User::factory()->create();
        $business->users()->attach($assistant, ['role' => 'assistant_accountant', 'status' => 'active']);

        $this->actingAs($assistant)->getJson("/api/v1/businesses/{$business->id}/reports/financial?type=payroll")->assertForbidden();
        $this->actingAs($assistant)->getJson("/api/v1/businesses/{$business->id}/reports/financial/export?type=payroll&format=xlsx")->assertForbidden();
        $this->actingAs($assistant)->getJson("/api/v1/businesses/{$business->id}/reports/financial?type=stock")->assertOk();
    }

    public function test_fixed_asset_register_shows_book_value_as_of_a_date(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $laptop = FixedAsset::factory()->for($business)->create(['asset_code' => 'FA-0001', 'name' => 'Laptop', 'category' => 'Equipment', 'cost' => '1200.00', 'acquired_on' => '2026-01-15']);
        FixedAsset::factory()->for($business)->create(['asset_code' => 'FA-0002', 'name' => 'Veturë', 'category' => 'Vehicles', 'cost' => '9000.00', 'opening_accumulated_depreciation' => '3000.00', 'acquired_on' => '2024-03-01']);
        FixedAsset::factory()->for($business)->create(['asset_code' => 'FA-0003', 'name' => 'Printer i vjetër', 'category' => 'Equipment', 'cost' => '300.00', 'acquired_on' => '2025-01-01', 'status' => 'disposed', 'disposed_on' => '2026-04-30']);
        FixedAsset::factory()->for($business)->create(['asset_code' => 'FA-0004', 'name' => 'Bought later', 'acquired_on' => '2026-09-01']);
        $this->depreciationRun($business, 2026, 5, 'approved', $laptop, '100.00');
        $this->depreciationRun($business, 2026, 6, 'approved', $laptop, '100.00');
        $this->depreciationRun($business, 2026, 7, 'approved', $laptop, '100.00');

        $this->actingAs($user)->getJson("/api/v1/businesses/{$business->id}/reports/financial?type=fixed_assets&to=2026-06-30")
            ->assertOk()
            ->assertJsonCount(3, 'data.rows')
            ->assertJsonPath('data.as_of', '2026-06-30')
            ->assertJsonPath('data.rows.0.asset_code', 'FA-0001')
            ->assertJsonPath('data.rows.0.accumulated_depreciation', '200.00')
            ->assertJsonPath('data.rows.0.book_value', '1000.00')
            ->assertJsonPath('data.rows.1.disposed_on', '2026-04-30')
            ->assertJsonPath('data.rows.1.book_value', '0.00')
            ->assertJsonPath('data.totals.cost', '10200.00')
            ->assertJsonPath('data.totals.book_value', '7000.00');

        $this->actingAs($user)->getJson("/api/v1/businesses/{$business->id}/reports/financial?type=fixed_assets&category=Vehicles&to=2026-06-30")
            ->assertOk()
            ->assertJsonCount(1, 'data.rows')
            ->assertJsonPath('data.totals.book_value', '6000.00');
    }

    public function test_new_reports_export_to_excel_and_pdf(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        InventoryItem::factory()->for($business)->create();

        foreach (['turnover', 'stock', 'payroll', 'fixed_assets'] as $type) {
            $this->actingAs($user)->get("/api/v1/businesses/{$business->id}/reports/financial/export?type={$type}&format=xlsx&from=2026-06-01&to=2026-06-30")
                ->assertOk()
                ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        }

        $pdf = $this->actingAs($user)->get("/api/v1/businesses/{$business->id}/reports/financial/export?type=stock&format=pdf&from=2026-06-01&to=2026-06-30")->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    public function test_reports_never_include_another_business(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $other = Business::factory()->create();
        InventoryItem::factory()->for($other)->create();
        FixedAsset::factory()->for($other)->create();
        $this->payrollRun($other, 2026, 6, 'approved', [['Someone Else', '800.00', '40.00', '40.00', '60.80', '699.20']]);

        foreach (['stock', 'payroll', 'fixed_assets'] as $type) {
            $this->actingAs($user)->getJson("/api/v1/businesses/{$business->id}/reports/financial?type={$type}&from=2026-01-01&to=2026-12-31")
                ->assertOk()
                ->assertJsonCount(0, 'data.rows');
        }
    }

    /** @param array<string, mixed> $attributes */
    private function issue(User $user, Business $business, array $attributes): void
    {
        $invoiceId = $this->createDraft($user, $business, $attributes, 'S18');
        $this->actingAs($user)->postJson("/api/v1/businesses/{$business->id}/invoices/{$invoiceId}/issue")->assertOk();
    }

    /** @param list<array{string, string, string, string, string, string}> $lines */
    private function payrollRun(Business $business, int $year, int $month, string $status, array $lines): void
    {
        $runId = DB::table('payroll_runs')->insertGetId([
            'business_id' => $business->id, 'period_year' => $year, 'period_month' => $month, 'pay_date' => sprintf('%04d-%02d-28', $year, $month), 'status' => $status,
            'gross_total' => '0.00', 'employee_contribution_total' => '0.00', 'employer_contribution_total' => '0.00', 'income_tax_total' => '0.00', 'net_total' => '0.00',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ($lines as [$name, $gross, $employee, $employer, $tax, $net]) {
            [$firstName, $lastName] = explode(' ', $name, 2);
            $employeeId = Employee::query()->where('business_id', $business->id)->where('first_name', $firstName)->value('id')
                ?? Employee::factory()->for($business)->create(['first_name' => $firstName, 'last_name' => $lastName])->id;
            DB::table('payroll_lines')->insert([
                'business_id' => $business->id, 'payroll_run_id' => $runId, 'employee_id' => $employeeId, 'employee_name' => $name, 'is_primary_employment' => true, 'gross_salary' => $gross, 'base_salary' => $gross,
                'employee_contribution' => $employee, 'employer_contribution' => $employer, 'taxable_income' => $gross, 'income_tax' => $tax, 'net_salary' => $net,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function depreciationRun(Business $business, int $year, int $month, string $status, FixedAsset $asset, string $amount): void
    {
        $runId = DB::table('depreciation_runs')->insertGetId(['business_id' => $business->id, 'period_year' => $year, 'period_month' => $month, 'status' => $status, 'total' => $amount, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('depreciation_lines')->insert(['business_id' => $business->id, 'depreciation_run_id' => $runId, 'fixed_asset_id' => $asset->id, 'opening_book_value' => '0.00', 'amount' => $amount, 'closing_book_value' => '0.00', 'created_at' => now(), 'updated_at' => now()]);
    }
}
