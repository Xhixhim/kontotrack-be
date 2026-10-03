<?php

namespace App\Services\Tasks;

use Illuminate\Support\Carbon;

/**
 * Turns the deadlines of an approved tax_calendar rule set into dated obligations for one year.
 *
 * Each deadline: {type, title, frequency: monthly|quarterly|annual, due_day, months_after_period (monthly/quarterly),
 * due_month (annual, month of the following year), applies_to: all|vat_registered|employers}.
 */
class TaxCalendarGenerator
{
    /**
     * @param  list<array<string, mixed>>  $deadlines
     * @return list<array{obligation_type: string, title: string, period_label: string, due_on: string}>
     */
    public function forYear(array $deadlines, int $year, bool $isVatRegistered, bool $hasEmployees): array
    {
        $obligations = [];

        foreach ($deadlines as $deadline) {
            $appliesTo = $deadline['applies_to'] ?? 'all';

            if (($appliesTo === 'vat_registered' && ! $isVatRegistered) || ($appliesTo === 'employers' && ! $hasEmployees)) {
                continue;
            }

            foreach ($this->periods($deadline, $year) as [$label, $titleSuffix, $dueOn]) {
                $obligations[] = [
                    'obligation_type' => (string) $deadline['type'],
                    'title' => trim($deadline['title'].' '.$titleSuffix),
                    'period_label' => $label,
                    'due_on' => $dueOn,
                ];
            }
        }

        usort($obligations, fn (array $left, array $right): int => [$left['due_on'], $left['obligation_type']] <=> [$right['due_on'], $right['obligation_type']]);

        return $obligations;
    }

    /**
     * @return list<string>
     */
    public function problems(mixed $deadlines): array
    {
        if (! is_array($deadlines) || $deadlines === [] || ! array_is_list($deadlines)) {
            return ['A tax_calendar rule set needs a deadlines rule with a non-empty deadlines list.'];
        }

        foreach ($deadlines as $deadline) {
            $frequency = is_array($deadline) ? ($deadline['frequency'] ?? null) : null;

            if (! is_array($deadline)
                || ! isset($deadline['type'], $deadline['title'], $deadline['due_day'])
                || preg_match('/^[a-z0-9_]{1,60}$/', (string) $deadline['type']) !== 1
                || ! in_array($frequency, ['monthly', 'quarterly', 'annual'], true)
                || ! is_int($deadline['due_day']) || $deadline['due_day'] < 1 || $deadline['due_day'] > 31
                || ! in_array($deadline['applies_to'] ?? 'all', ['all', 'vat_registered', 'employers'], true)
                || ($frequency === 'annual' && (! is_int($deadline['due_month'] ?? null) || $deadline['due_month'] < 1 || $deadline['due_month'] > 12))
                || ($frequency !== 'annual' && (! is_int($deadline['months_after_period'] ?? null) || $deadline['months_after_period'] < 0 || $deadline['months_after_period'] > 12))) {
                return ['Each deadline needs a type, title, frequency (monthly, quarterly, annual), due_day 1–31, months_after_period for monthly/quarterly or due_month for annual, and applies_to (all, vat_registered, employers).'];
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $deadline
     * @return list<array{string, string, string}>
     */
    private function periods(array $deadline, int $year): array
    {
        $dueDay = (int) $deadline['due_day'];

        return match ($deadline['frequency']) {
            'monthly' => array_map(fn (int $month): array => [
                sprintf('%04d-%02d', $year, $month),
                sprintf('%04d-%02d', $year, $month),
                $this->dueDate(Carbon::create($year, $month, 1)->addMonthsNoOverflow((int) $deadline['months_after_period']), $dueDay),
            ], range(1, 12)),
            'quarterly' => array_map(fn (int $quarter): array => [
                "{$year}-Q{$quarter}",
                "Q{$quarter} {$year}",
                $this->dueDate(Carbon::create($year, $quarter * 3, 1)->addMonthsNoOverflow((int) $deadline['months_after_period']), $dueDay),
            ], range(1, 4)),
            default => [[
                (string) $year,
                (string) $year,
                $this->dueDate(Carbon::create($year + 1, (int) $deadline['due_month'], 1), $dueDay),
            ]],
        };
    }

    private function dueDate(Carbon $month, int $dueDay): string
    {
        return $month->copy()->day(min($dueDay, $month->daysInMonth))->toDateString();
    }
}
