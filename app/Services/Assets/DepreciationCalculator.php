<?php

namespace App\Services\Assets;

/**
 * Monthly depreciation for one asset, never taking the book value below the residual value.
 */
class DepreciationCalculator
{
    public function monthlyAmount(string $method, int $costCents, int $residualCents, int $bookValueCents, ?int $usefulLifeMonths, ?string $annualRatePercent): int
    {
        $depreciableRemaining = $bookValueCents - $residualCents;

        if ($depreciableRemaining <= 0) {
            return 0;
        }

        $amount = match ($method) {
            'straight_line' => $this->divideRounded($costCents - $residualCents, max(1, (int) $usefulLifeMonths)),
            'declining_balance' => $this->divideRounded($bookValueCents * $this->toScaledFour((string) $annualRatePercent), 12 * 1_000_000),
            default => 0,
        };

        return min($amount, $depreciableRemaining);
    }

    private function divideRounded(int $numerator, int $denominator): int
    {
        return intdiv($numerator + intdiv($denominator, 2), $denominator);
    }

    private function toScaledFour(string $percent): int
    {
        [$whole, $fraction] = array_pad(explode('.', $percent, 2), 2, '');

        return ((int) $whole * 10_000) + (int) substr(str_pad($fraction, 4, '0'), 0, 4);
    }
}
