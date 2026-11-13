<?php

namespace Tests\Feature\Api\V1;

use App\Models\AccountingPeriod;
use App\Models\Business;
use App\Models\Employee;
use App\Models\InventoryItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PeriodCloseChecksTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Business $business;

    private AccountingPeriod $period;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-07-05 10:00:00');
        $this->user = User::factory()->create();
        $this->business = Business::factory()->create();
        $this->business->users()->attach($this->user, ['role' => 'accountant', 'status' => 'active']);
        $this->period = AccountingPeriod::factory()->for($this->business)->create(['name' => 'Maj–Qershor 2026', 'starts_on' => '2026-05-01', 'ends_on' => '2026-06-30']);
    }

    public function test_passes_payroll_and_stock_when_the_business_has_neither(): void
    {
        $checks = $this->checks();

        $this->assertSame(['passed', 'Nobody was employed in this period.'], [$checks['payroll']['status'], $checks['payroll']['detail']]);
        $this->assertSame(['passed', 'This business does not track stock.'], [$checks['inventory']['status'], $checks['inventory']['detail']]);
    }

    public function test_warns_about_months_with_employees_but_no_approved_payroll(): void
    {
        Employee::factory()->for($this->business)->create(['employment_start' => '2026-06-10', 'employment_end' => null]);
        $this->payrollRun(2026, 6, 'draft');

        $payroll = $this->checks()['payroll'];
        $this->assertSame('warning', $payroll['status']);
        $this->assertSame('The payroll is not approved for: 06.2026. Salaries of those months are missing from the books.', $payroll['detail']);

        DB::table('payroll_runs')->where('business_id', $this->business->id)->update(['status' => 'approved']);

        $payroll = $this->checks()['payroll'];
        $this->assertSame(['passed', 'The payroll is approved and posted for every month of this period.'], [$payroll['status'], $payroll['detail']]);
    }

    public function test_warns_about_stock_below_zero_at_period_end(): void
    {
        $warehouse = Warehouse::factory()->for($this->business)->create(['name' => 'Depo']);
        $coffee = InventoryItem::factory()->for($this->business)->create(['name' => 'Kafe']);
        $sugar = InventoryItem::factory()->for($this->business)->create(['name' => 'Sheqer']);
        $move = fn (InventoryItem $item, string $delta, string $date) => StockMovement::factory()->for($this->business)->create(['inventory_item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'quantity_delta' => $delta, 'occurred_on' => $date]);
        $move($coffee, '5.0000', '2026-05-02');
        $move($coffee, '-8.0000', '2026-06-20');
        $move($coffee, '10.0000', '2026-07-01');
        $move($sugar, '3.0000', '2026-05-02');

        $inventory = $this->checks()['inventory'];
        $this->assertSame('warning', $inventory['status']);
        $this->assertSame('Stock is below zero at period end for: Kafe (Depo). Record the missing purchases or a stock count.', $inventory['detail']);

        $move($coffee, '3.0000', '2026-06-25');
        $inventory = $this->checks()['inventory'];
        $this->assertSame(['passed', 'No item has a negative quantity at period end.'], [$inventory['status'], $inventory['detail']]);
    }

    public function test_the_checks_are_translated_when_albanian_is_asked_for(): void
    {
        Employee::factory()->for($this->business)->create(['employment_start' => '2026-05-01', 'employment_end' => null]);

        $checks = collect($this->actingAs($this->user)->withHeaders(['Accept-Language' => 'sq'])
            ->getJson("/api/v1/businesses/{$this->business->id}/periods/{$this->period->id}/closing-checks")->assertOk()->json('data.checks'))->keyBy('key');

        $this->assertSame('Kontrolli i pagave', $checks['payroll']['label']);
        $this->assertSame('Pagat nuk janë miratuar për: 05.2026, 06.2026. Pagat e atyre muajve mungojnë në libra.', $checks['payroll']['detail']);
        $this->assertSame('Ky biznes nuk përcjell stok.', $checks['inventory']['detail']);
    }

    /** @return array<string, array<string, mixed>> */
    private function checks(): array
    {
        return collect($this->actingAs($this->user)->getJson("/api/v1/businesses/{$this->business->id}/periods/{$this->period->id}/closing-checks")->assertOk()->json('data.checks'))->keyBy('key')->all();
    }

    private function payrollRun(int $year, int $month, string $status): void
    {
        DB::table('payroll_runs')->insert([
            'business_id' => $this->business->id, 'period_year' => $year, 'period_month' => $month, 'pay_date' => sprintf('%04d-%02d-28', $year, $month), 'status' => $status,
            'gross_total' => '0.00', 'employee_contribution_total' => '0.00', 'employer_contribution_total' => '0.00', 'income_tax_total' => '0.00', 'net_total' => '0.00',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
