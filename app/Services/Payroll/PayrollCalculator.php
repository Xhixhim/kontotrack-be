<?php

namespace App\Services\Payroll;

use InvalidArgumentException;

/**
 * Gross-to-net salary calculation driven entirely by an approved payroll rule configuration.
 *
 * Expected configuration:
 * - pension_contributions: {employee_percent, employer_percent}
 * - income_tax: {primary_brackets: [{up_to: amount|null, rate_percent}], secondary_rate_percent}
 *
 * Bracket limits are monthly taxable-income amounts in ascending order; the last bracket has up_to null.
 */
class PayrollCalculator
{
    /**
     * @param  array{pension_contributions: array<string, mixed>, income_tax: array<string, mixed>}  $rules
     * @return array{gross_cents: int, employee_contribution_cents: int, employer_contribution_cents: int, taxable_income_cents: int, income_tax_cents: int, net_cents: int}
     */
    public function calculate(int $grossCents, bool $isPrimaryEmployment, array $rules): array
    {
        $this->assertValid($rules);

        $employeeContribution = $this->percentOf($grossCents, (string) $rules['pension_contributions']['employee_percent']);
        $employerContribution = $this->percentOf($grossCents, (string) $rules['pension_contributions']['employer_percent']);
        $taxableIncome = $grossCents - $employeeContribution;
        $incomeTax = $isPrimaryEmployment
            ? $this->progressiveTax($taxableIncome, $rules['income_tax']['primary_brackets'])
            : $this->percentOf($taxableIncome, (string) $rules['income_tax']['secondary_rate_percent']);

        return [
            'gross_cents' => $grossCents,
            'employee_contribution_cents' => $employeeContribution,
            'employer_contribution_cents' => $employerContribution,
            'taxable_income_cents' => $taxableIncome,
            'income_tax_cents' => $incomeTax,
            'net_cents' => $grossCents - $employeeContribution - $incomeTax,
        ];
    }

    /**
     * Return a list of problems with the configuration; an empty list means it is usable.
     *
     * @param  array<string, mixed>  $rules
     * @return list<string>
     */
    public function problems(array $rules): array
    {
        $problems = [];
        $pension = $rules['pension_contributions'] ?? null;

        if (! is_array($pension) || ! $this->isPercent($pension['employee_percent'] ?? null) || ! $this->isPercent($pension['employer_percent'] ?? null)) {
            $problems[] = 'pension_contributions needs employee_percent and employer_percent between 0 and 100.';
        }

        $incomeTax = $rules['income_tax'] ?? null;
        $brackets = is_array($incomeTax) ? ($incomeTax['primary_brackets'] ?? null) : null;

        if (! is_array($incomeTax) || ! $this->isPercent($incomeTax['secondary_rate_percent'] ?? null)) {
            $problems[] = 'income_tax needs a secondary_rate_percent between 0 and 100.';
        }

        if (! is_array($brackets) || $brackets === [] || ! array_is_list($brackets)) {
            $problems[] = 'income_tax needs a non-empty primary_brackets list.';

            return $problems;
        }

        $previousLimit = -1;

        foreach ($brackets as $index => $bracket) {
            $isLast = $index === count($brackets) - 1;
            $limit = is_array($bracket) ? ($bracket['up_to'] ?? null) : null;

            if (! is_array($bracket) || ! $this->isPercent($bracket['rate_percent'] ?? null)) {
                $problems[] = 'Every income tax bracket needs a rate_percent between 0 and 100.';

                break;
            }

            if ($isLast && $limit !== null) {
                $problems[] = 'The last income tax bracket must have up_to null.';
            }

            if (! $isLast && (! is_numeric($limit) || $this->toCents((string) $limit) <= $previousLimit)) {
                $problems[] = 'Income tax bracket limits must be ascending amounts.';

                break;
            }

            $previousLimit = $limit === null ? $previousLimit : $this->toCents((string) $limit);
        }

        return $problems;
    }

    /** @param list<array{up_to: string|int|float|null, rate_percent: string|int|float}> $brackets */
    private function progressiveTax(int $taxableCents, array $brackets): int
    {
        $taxCents = 0;
        $lowerCents = 0;

        foreach ($brackets as $bracket) {
            if ($taxableCents <= $lowerCents) {
                break;
            }

            $upperCents = $bracket['up_to'] === null ? $taxableCents : min($taxableCents, $this->toCents((string) $bracket['up_to']));
            $taxCents += $this->percentOf($upperCents - $lowerCents, (string) $bracket['rate_percent']);
            $lowerCents = $upperCents;
        }

        return $taxCents;
    }

    private function percentOf(int $amountCents, string $ratePercent): int
    {
        $product = $amountCents * $this->toScaledFour($ratePercent);
        $whole = intdiv($product, 1_000_000);

        return $whole + (($product % 1_000_000) >= 500_000 ? 1 : 0);
    }

    /** @param array<string, mixed> $rules */
    private function assertValid(array $rules): void
    {
        $problems = $this->problems($rules);

        if ($problems !== []) {
            throw new InvalidArgumentException(implode(' ', $problems));
        }
    }

    private function isPercent(mixed $value): bool
    {
        return is_numeric($value) && (float) $value >= 0 && (float) $value <= 100;
    }

    private function toCents(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', ltrim($amount, '+'), 2), 2, '');

        return ((int) $whole * 100) + (int) substr(str_pad($fraction, 2, '0'), 0, 2);
    }

    private function toScaledFour(string $percent): int
    {
        [$whole, $fraction] = array_pad(explode('.', $percent, 2), 2, '');

        return ((int) $whole * 10_000) + (int) substr(str_pad($fraction, 4, '0'), 0, 4);
    }
}
