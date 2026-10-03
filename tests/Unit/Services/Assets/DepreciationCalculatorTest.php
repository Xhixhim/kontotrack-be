<?php

namespace Tests\Unit\Services\Assets;

use App\Services\Assets\DepreciationCalculator;
use PHPUnit\Framework\TestCase;

class DepreciationCalculatorTest extends TestCase
{
    public function test_straight_line_spreads_the_depreciable_amount_evenly(): void
    {
        $this->assertSame(10_000, (new DepreciationCalculator)->monthlyAmount('straight_line', 120_000, 0, 120_000, 12, null));
    }

    public function test_straight_line_never_goes_below_the_residual_value(): void
    {
        $this->assertSame(3_000, (new DepreciationCalculator)->monthlyAmount('straight_line', 120_000, 20_000, 23_000, 12, null));
        $this->assertSame(0, (new DepreciationCalculator)->monthlyAmount('straight_line', 120_000, 20_000, 20_000, 12, null));
    }

    public function test_declining_balance_applies_a_twelfth_of_the_annual_rate_to_the_book_value(): void
    {
        $calculator = new DepreciationCalculator;

        $this->assertSame(2_000, $calculator->monthlyAmount('declining_balance', 100_000, 0, 100_000, null, '24'));
        $this->assertSame(1_960, $calculator->monthlyAmount('declining_balance', 100_000, 0, 98_000, null, '24'));
    }

    public function test_rounds_to_the_nearest_cent(): void
    {
        $this->assertSame(3_333, (new DepreciationCalculator)->monthlyAmount('straight_line', 100_000, 0, 100_000, 30, null));
    }
}
