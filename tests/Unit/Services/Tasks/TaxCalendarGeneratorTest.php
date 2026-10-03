<?php

namespace Tests\Unit\Services\Tasks;

use App\Services\Tasks\TaxCalendarGenerator;
use PHPUnit\Framework\TestCase;

class TaxCalendarGeneratorTest extends TestCase
{
    /** @return list<array<string, mixed>> Test fixture deadlines, not official dates. */
    private function deadlines(): array
    {
        return [
            ['type' => 'vat_return', 'title' => 'VAT return', 'frequency' => 'monthly', 'due_day' => 20, 'months_after_period' => 1, 'applies_to' => 'vat_registered'],
            ['type' => 'payroll_declaration', 'title' => 'Payroll declaration', 'frequency' => 'monthly', 'due_day' => 15, 'months_after_period' => 1, 'applies_to' => 'employers'],
            ['type' => 'quarterly_advance', 'title' => 'Advance payment', 'frequency' => 'quarterly', 'due_day' => 31, 'months_after_period' => 1, 'applies_to' => 'all'],
            ['type' => 'annual_return', 'title' => 'Annual return', 'frequency' => 'annual', 'due_day' => 31, 'due_month' => 3, 'applies_to' => 'all'],
        ];
    }

    public function test_generates_monthly_quarterly_and_annual_deadlines_for_a_vat_registered_employer(): void
    {
        $obligations = (new TaxCalendarGenerator)->forYear($this->deadlines(), 2026, true, true);

        $this->assertCount(12 + 12 + 4 + 1, $obligations);
        $vatJanuary = collect($obligations)->first(fn (array $item): bool => $item['obligation_type'] === 'vat_return' && $item['period_label'] === '2026-01');
        $this->assertSame('2026-02-20', $vatJanuary['due_on']);
        $vatDecember = collect($obligations)->first(fn (array $item): bool => $item['obligation_type'] === 'vat_return' && $item['period_label'] === '2026-12');
        $this->assertSame('2027-01-20', $vatDecember['due_on']);
    }

    public function test_clamps_the_due_day_to_the_length_of_the_month(): void
    {
        $obligations = collect((new TaxCalendarGenerator)->forYear($this->deadlines(), 2026, false, false));

        $this->assertSame('2026-04-30', $obligations->firstWhere('period_label', '2026-Q1')['due_on']);
        $this->assertSame('2027-03-31', $obligations->firstWhere('period_label', '2026')['due_on']);
    }

    public function test_skips_deadlines_that_do_not_apply_to_the_business(): void
    {
        $types = collect((new TaxCalendarGenerator)->forYear($this->deadlines(), 2026, false, false))->pluck('obligation_type')->unique()->values()->all();

        $this->assertSame(['quarterly_advance', 'annual_return'], $types);
    }

    public function test_reports_an_invalid_deadline_configuration(): void
    {
        $generator = new TaxCalendarGenerator;

        $this->assertSame([], $generator->problems($this->deadlines()));
        $this->assertNotSame([], $generator->problems([['type' => 'vat_return', 'title' => 'VAT', 'frequency' => 'weekly', 'due_day' => 20]]));
        $this->assertNotSame([], $generator->problems([]));
    }
}
