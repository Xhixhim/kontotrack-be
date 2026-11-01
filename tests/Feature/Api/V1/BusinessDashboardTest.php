<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Employee;
use App\Models\InventoryItem;
use App\Models\PayrollRun;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesBookkeepingFixtures;
use Tests\TestCase;

class BusinessDashboardTest extends TestCase
{
    use CreatesBookkeepingFixtures;
    use RefreshDatabase;

    public function test_summarises_payroll_of_the_period_months(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $october = $this->payrollRun($business, 10, 'draft', '1200.00');
        $this->payrollLine($business, $october, Employee::factory()->for($business)->create(), '700.00');
        $this->payrollLine($business, $october, Employee::factory()->for($business)->create(), '500.00');
        $this->payrollRun($business, 9, 'approved', '900.00');

        $this->actingAs($user)->getJson("/api/v1/businesses/{$business->id}/dashboard?from=2026-10-01&to=2026-10-31")
            ->assertOk()
            ->assertJsonPath('data.payroll_gross', '1200.00')
            ->assertJsonPath('data.payroll_employees', 2)
            ->assertJsonPath('data.payroll_status', 'draft');

        $this->actingAs($user)->getJson("/api/v1/businesses/{$business->id}/dashboard?from=2026-09-01&to=2026-09-30")
            ->assertJsonPath('data.payroll_gross', '900.00')
            ->assertJsonPath('data.payroll_status', 'approved');

        $this->actingAs($user)->getJson("/api/v1/businesses/{$business->id}/dashboard?from=2026-08-01&to=2026-08-31")
            ->assertJsonPath('data.payroll_gross', '0.00')
            ->assertJsonPath('data.payroll_employees', 0)
            ->assertJsonPath('data.payroll_status', null);
    }

    public function test_values_stock_on_hand_at_the_end_of_the_period(): void
    {
        [$user, $business] = $this->bookkeepingBusiness();
        $warehouse = Warehouse::factory()->for($business)->create();
        $flour = InventoryItem::factory()->for($business)->create(['purchase_price' => '2.5000']);
        $sugar = InventoryItem::factory()->for($business)->create(['purchase_price' => '3.0000']);
        $soldOut = InventoryItem::factory()->for($business)->create(['purchase_price' => '9.9900']);
        $move = fn (InventoryItem $item, string $quantity, string $on) => StockMovement::factory()->for($business)->create([
            'inventory_item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'quantity_delta' => $quantity, 'occurred_on' => $on,
        ]);
        $move($flour, '10.0000', '2026-10-01');
        $move($flour, '-4.0000', '2026-10-20');
        $move($sugar, '2.0000', '2026-11-05');
        $move($soldOut, '3.0000', '2026-10-02');
        $move($soldOut, '-3.0000', '2026-10-03');

        $this->actingAs($user)->getJson("/api/v1/businesses/{$business->id}/dashboard?from=2026-10-01&to=2026-10-31")
            ->assertOk()
            ->assertJsonPath('data.stock_value', '15.00')
            ->assertJsonPath('data.stock_items', 1);
    }

    public function test_withholds_payroll_from_members_who_cannot_see_payroll(): void
    {
        [, $business] = $this->bookkeepingBusiness();
        $this->payrollRun($business, 10, 'approved', '1200.00');
        $assistant = User::factory()->create();
        $business->users()->attach($assistant, ['role' => 'assistant_accountant', 'status' => 'active']);

        $this->actingAs($assistant)->getJson("/api/v1/businesses/{$business->id}/dashboard?from=2026-10-01&to=2026-10-31")
            ->assertOk()
            ->assertJsonPath('data.payroll_gross', null)
            ->assertJsonPath('data.payroll_employees', null)
            ->assertJsonPath('data.payroll_status', null)
            ->assertJsonPath('data.stock_value', '0.00');
    }

    private function payrollRun(Business $business, int $month, string $status, string $gross): PayrollRun
    {
        return PayrollRun::query()->create([
            'business_id' => $business->id, 'period_year' => 2026, 'period_month' => $month,
            'pay_date' => sprintf('2026-%02d-05', $month + 1), 'status' => $status, 'gross_total' => $gross,
        ]);
    }

    private function payrollLine(Business $business, PayrollRun $run, Employee $employee, string $gross): void
    {
        DB::table('payroll_lines')->insert([
            'business_id' => $business->id, 'payroll_run_id' => $run->id, 'employee_id' => $employee->id,
            'employee_name' => 'Test employee', 'is_primary_employment' => true, 'gross_salary' => $gross,
            'employee_contribution' => '0.00', 'employer_contribution' => '0.00', 'taxable_income' => $gross,
            'income_tax' => '0.00', 'net_salary' => $gross, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
