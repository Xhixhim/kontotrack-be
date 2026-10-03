<?php

namespace Tests\Unit\Services\Payroll;

use App\Services\Payroll\PayrollCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PayrollCalculatorTest extends TestCase
{
    /**
     * Test fixture rules only; real parameters come from an approved payroll rule set.
     *
     * @return array{pension_contributions: array<string, mixed>, income_tax: array<string, mixed>}
     */
    private function rules(): array
    {
        return [
            'pension_contributions' => ['employee_percent' => '5', 'employer_percent' => '5'],
            'income_tax' => [
                'primary_brackets' => [
                    ['up_to' => '250', 'rate_percent' => '0'],
                    ['up_to' => '450', 'rate_percent' => '8'],
                    ['up_to' => null, 'rate_percent' => '10'],
                ],
                'secondary_rate_percent' => '10',
            ],
        ];
    }

    /** @return array<string, array{int, bool, int, int, int, int}> */
    public static function salaries(): array
    {
        return [
            'below the first limit' => [20_000, true, 1_000, 19_000, 0, 19_000],
            'inside the second bracket' => [40_000, true, 2_000, 38_000, 1_040, 36_960],
            'across every bracket' => [100_000, true, 5_000, 95_000, 6_600, 88_400],
            'secondary employment uses the flat rate' => [100_000, false, 5_000, 95_000, 9_500, 85_500],
        ];
    }

    #[DataProvider('salaries')]
    public function test_calculates_contributions_tax_and_net(int $grossCents, bool $isPrimary, int $contributionCents, int $taxableCents, int $taxCents, int $netCents): void
    {
        $result = (new PayrollCalculator)->calculate($grossCents, $isPrimary, $this->rules());

        $this->assertSame($contributionCents, $result['employee_contribution_cents']);
        $this->assertSame($contributionCents, $result['employer_contribution_cents']);
        $this->assertSame($taxableCents, $result['taxable_income_cents']);
        $this->assertSame($taxCents, $result['income_tax_cents']);
        $this->assertSame($netCents, $result['net_cents']);
    }

    public function test_rounds_each_component_half_up_to_the_cent(): void
    {
        $result = (new PayrollCalculator)->calculate(33_333, true, $this->rules());

        $this->assertSame(1_667, $result['employee_contribution_cents']);
        $this->assertSame(31_666, $result['taxable_income_cents']);
        $this->assertSame(533, $result['income_tax_cents']);
        $this->assertSame(31_133, $result['net_cents']);
    }

    public function test_reports_problems_with_an_incomplete_configuration(): void
    {
        $problems = (new PayrollCalculator)->problems([
            'pension_contributions' => ['employee_percent' => '150'],
            'income_tax' => ['primary_brackets' => [['up_to' => '500', 'rate_percent' => '4']], 'secondary_rate_percent' => '10'],
        ]);

        $this->assertSame([
            'pension_contributions needs employee_percent and employer_percent between 0 and 100.',
            'The last income tax bracket must have up_to null.',
        ], $problems);
    }

    public function test_rejects_brackets_that_are_not_ascending(): void
    {
        $rules = $this->rules();
        $rules['income_tax']['primary_brackets'][1]['up_to'] = '100';

        $this->assertSame(['Income tax bracket limits must be ascending amounts.'], (new PayrollCalculator)->problems($rules));
    }

    public function test_refuses_to_calculate_with_invalid_rules(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new PayrollCalculator)->calculate(10_000, true, ['pension_contributions' => [], 'income_tax' => []]);
    }
}
