<?php

namespace App\Services\Tax;

use App\Models\Account;
use App\Models\Business;
use App\Models\User;
use App\Repositories\Contracts\ComplianceRuleSetRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The quarterly tax small businesses pay on gross income instead of on profit.
 *
 * Rates, the minimum payment, the turnover limit and the activity groups all come from the approved
 * "small_business_tax" rule set effective at the end of the quarter; nothing is assumed here. The result
 * is a worksheet for the bookkeeper to check and declare, not a filing.
 */
class GrossIncomeTaxService
{
    public const DOMAIN = 'small_business_tax';

    public const RULE_KEY = 'gross_income_tax';

    public function __construct(private ComplianceRuleSetRepository $ruleSetRepository) {}

    /**
     * @return array<string, mixed>
     */
    public function worksheet(Business $business, int $year, int $quarter): array
    {
        $from = CarbonImmutable::create($year, ($quarter - 1) * 3 + 1, 1);
        $to = $from->addMonths(2)->endOfMonth();
        $ruleSet = $this->ruleSetRepository->findEffective('XK', self::DOMAIN, $to->toDateString());

        if ($ruleSet === null) {
            throw ValidationException::withMessages(['quarter' => ['No approved small-business tax rules are effective for this quarter.']]);
        }

        $configuration = $ruleSet->rules->firstWhere('rule_key', self::RULE_KEY)?->configuration;
        $problems = $this->problems($configuration);

        if ($problems !== []) {
            throw ValidationException::withMessages(['compliance_rule_set' => $problems]);
        }

        $activities = collect($configuration['activities'])->keyBy('key');
        $quarterIncome = $this->revenueByAccount($business, $from->toDateString(), $to->toDateString());
        $yearToDateCents = array_sum($this->revenueByAccount($business, $from->startOfYear()->toDateString(), $to->toDateString()));
        $totals = $activities->map(fn (): int => 0)->all();
        $accounts = [];
        $unassignedCents = 0;

        foreach ($business->accounts()->where('account_type', 'revenue')->orderBy('code')->get() as $account) {
            $cents = $quarterIncome[$account->getKey()] ?? 0;
            $activity = $account->metadata['tax_activity'] ?? null;
            $activity = is_string($activity) && $activities->has($activity) ? $activity : null;

            if ($cents === 0 && $activity === null) {
                continue;
            }

            if ($activity === null) {
                $unassignedCents += $cents;
            } else {
                $totals[$activity] += $cents;
            }

            $accounts[] = ['id' => (int) $account->getKey(), 'code' => $account->code, 'name' => $account->name, 'gross_income' => $this->fromCents($cents), 'activity' => $activity];
        }

        $taxCents = 0;
        $lines = [];

        foreach ($activities as $key => $activity) {
            // Negative income (more credit notes than sales) is not taxed and does not reduce other activities.
            $lineTax = (int) round(max(0, $totals[$key]) * (float) $activity['rate_percent'] / 100);
            $taxCents += $lineTax;
            $lines[] = ['key' => $key, 'label' => $activity['label'], 'rate_percent' => (string) $activity['rate_percent'], 'gross_income' => $this->fromCents($totals[$key]), 'tax' => $this->fromCents($lineTax)];
        }

        $minimumCents = $this->toCents((string) $configuration['minimum_per_quarter']);
        $thresholdCents = $this->toCents((string) $configuration['threshold_amount']);

        return [
            'year' => $year,
            'quarter' => $quarter,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'currency' => $business->currency,
            'rule_set' => ['version' => $ruleSet->version, 'source_title' => $ruleSet->source_title, 'source_url' => $ruleSet->source_url],
            'activities' => $lines,
            'accounts' => $accounts,
            'unassigned_gross_income' => $this->fromCents($unassignedCents),
            // Every revenue account with income needs an activity before the figure can be relied on.
            'complete' => $unassignedCents === 0,
            'gross_income' => $this->fromCents(array_sum($totals) + $unassignedCents),
            'calculated_tax' => $this->fromCents($taxCents),
            'minimum_per_quarter' => $this->fromCents($minimumCents),
            'tax_due' => $this->fromCents(max($taxCents, $minimumCents)),
            'minimum_applied' => $taxCents < $minimumCents,
            'year_to_date_gross_income' => $this->fromCents($yearToDateCents),
            'threshold_amount' => $this->fromCents($thresholdCents),
            'over_threshold' => $yearToDateCents > $thresholdCents,
        ];
    }

    /**
     * Say which activity group the income of a revenue account belongs to (or clear it with null).
     */
    public function assignActivity(Business $business, User $user, int $accountId, ?string $activity): Account
    {
        return DB::transaction(function () use ($business, $user, $accountId, $activity): Account {
            $account = $business->accounts()->whereKey($accountId)->lockForUpdate()->firstOrFail();

            if ($account->account_type !== 'revenue') {
                throw ValidationException::withMessages(['account' => ['Only revenue accounts are grouped by activity.']]);
            }

            $old = $account->metadata['tax_activity'] ?? null;
            $metadata = $account->metadata ?? [];

            if ($activity === null) {
                unset($metadata['tax_activity']);
            } else {
                $metadata['tax_activity'] = $activity;
            }

            $account->update(['metadata' => $metadata === [] ? null : $metadata]);
            $business->auditLogs()->create([
                'user_id' => $user->getKey(),
                'action' => 'account.tax_activity_changed',
                'subject_type' => Account::class,
                'subject_id' => $account->getKey(),
                'old_values' => ['tax_activity' => $old],
                'new_values' => ['tax_activity' => $activity],
            ]);

            return $account;
        });
    }

    /**
     * What is wrong with a gross_income_tax rule, in words an administrator can act on.
     *
     * @return list<string>
     */
    public function problems(mixed $configuration): array
    {
        if (! is_array($configuration)) {
            return ['The rule set needs a gross_income_tax rule.'];
        }

        $problems = [];

        if (! is_numeric($configuration['threshold_amount'] ?? null) || (float) $configuration['threshold_amount'] <= 0) {
            $problems[] = 'gross_income_tax needs a positive threshold_amount.';
        }

        if (! is_numeric($configuration['minimum_per_quarter'] ?? null) || (float) $configuration['minimum_per_quarter'] < 0) {
            $problems[] = 'gross_income_tax needs a minimum_per_quarter of zero or more.';
        }

        $activities = $configuration['activities'] ?? null;

        if (! is_array($activities) || $activities === []) {
            return [...$problems, 'gross_income_tax needs at least one activity with key, label and rate_percent.'];
        }

        $keys = [];

        foreach ($activities as $activity) {
            $key = is_array($activity) ? ($activity['key'] ?? null) : null;
            $rate = is_array($activity) ? ($activity['rate_percent'] ?? null) : null;

            if (! is_string($key) || preg_match('/^[a-z][a-z0-9_]{0,39}$/', $key) !== 1 || ! is_string($activity['label'] ?? null) || $activity['label'] === '' || ! is_numeric($rate) || (float) $rate < 0 || (float) $rate > 100) {
                $problems[] = 'Each gross_income_tax activity needs a simple key, a label and a rate_percent between 0 and 100.';

                break;
            }

            $keys[] = $key;
        }

        if (count($keys) !== count(array_unique($keys))) {
            $problems[] = 'gross_income_tax activity keys must be unique.';
        }

        return $problems;
    }

    /** @return array<int, int> cents of net credit per revenue account */
    private function revenueByAccount(Business $business, string $from, string $to): array
    {
        return DB::table('journal_lines as lines')
            ->join('journal_entries as entries', function ($join): void {
                $join->on('entries.id', '=', 'lines.journal_entry_id')->on('entries.business_id', '=', 'lines.business_id');
            })
            ->join('accounts', function ($join): void {
                $join->on('accounts.id', '=', 'lines.account_id')->on('accounts.business_id', '=', 'lines.business_id');
            })
            ->where('lines.business_id', (int) $business->getKey())
            ->where('entries.status', 'posted')
            ->where('entries.entry_type', '!=', 'year_end_closing')
            ->whereDate('entries.entry_date', '>=', $from)
            ->whereDate('entries.entry_date', '<=', $to)
            ->where('accounts.account_type', 'revenue')
            ->groupBy('accounts.id')
            ->selectRaw('accounts.id, SUM(lines.credit) AS credit_total, SUM(lines.debit) AS debit_total')
            ->get()
            ->mapWithKeys(fn (object $row): array => [(int) $row->id => $this->toCents((string) $row->credit_total) - $this->toCents((string) $row->debit_total)])
            ->all();
    }

    private function toCents(string $amount): int
    {
        $negative = str_starts_with($amount, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($amount, '+-'), 2), 2, '');
        $cents = ((int) $whole * 100) + (int) substr(str_pad($fraction, 2, '0'), 0, 2);

        return $negative ? -$cents : $cents;
    }

    private function fromCents(int $amount): string
    {
        $sign = $amount < 0 ? '-' : '';
        $amount = abs($amount);

        return $sign.intdiv($amount, 100).'.'.str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
    }
}
