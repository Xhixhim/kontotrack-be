<?php

namespace App\Services\Compliance;

use App\Models\AuditLog;
use App\Models\ComplianceRuleSet;
use App\Models\User;
use App\Repositories\Contracts\ComplianceRuleSetRepository;
use App\Services\Payroll\PayrollCalculator;
use App\Services\Tasks\TaxCalendarGenerator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ComplianceRuleSetService
{
    public function __construct(
        private ComplianceRuleSetRepository $ruleSetRepository,
        private PayrollCalculator $payrollCalculator,
        private TaxCalendarGenerator $taxCalendarGenerator,
    ) {}

    /** @return LengthAwarePaginator<int, ComplianceRuleSet> */
    public function listForUser(bool $includeDrafts, ?string $jurisdiction, ?string $domain): LengthAwarePaginator
    {
        return $this->ruleSetRepository->listForUser($includeDrafts, $jurisdiction, $domain);
    }

    public function findEffective(string $jurisdiction, string $domain, string $date): ?ComplianceRuleSet
    {
        return $this->ruleSetRepository->findEffective($jurisdiction, $domain, $date);
    }

    /** @param array<string, mixed> $attributes */
    public function createDraft(User $user, array $attributes): ComplianceRuleSet
    {
        $rules = $this->validatedRuleRows($attributes['rules']);
        unset($attributes['rules']);
        $attributes['approval_status'] = 'draft';
        $attributes['created_by'] = $user->getKey();

        return DB::transaction(function () use ($user, $attributes, $rules): ComplianceRuleSet {
            $ruleSet = $this->ruleSetRepository->createDraft($attributes, $rules);
            $this->audit($user, $ruleSet, 'compliance_rule_set.draft_created', null, $this->auditValues($ruleSet));

            return $ruleSet;
        });
    }

    /** @param array<string, mixed> $attributes */
    public function updateDraft(User $user, int $ruleSetId, array $attributes): ComplianceRuleSet
    {
        $rules = $this->validatedRuleRows($attributes['rules']);
        unset($attributes['rules']);

        return DB::transaction(function () use ($user, $ruleSetId, $attributes, $rules): ComplianceRuleSet {
            $ruleSet = $this->ruleSetRepository->findForUpdate($ruleSetId);

            if ($ruleSet->approval_status !== 'draft') {
                throw ValidationException::withMessages(['compliance_rule_set' => ['Approved compliance rule sets are immutable.']]);
            }

            $oldValues = $this->auditValues($ruleSet);
            $updatedRuleSet = $this->ruleSetRepository->updateDraft($ruleSet, $attributes, $rules);
            $this->audit($user, $updatedRuleSet, 'compliance_rule_set.draft_updated', $oldValues, $this->auditValues($updatedRuleSet));

            return $updatedRuleSet;
        });
    }

    public function approve(User $user, int $ruleSetId): ComplianceRuleSet
    {
        return DB::transaction(function () use ($user, $ruleSetId): ComplianceRuleSet {
            $ruleSet = $this->ruleSetRepository->findForUpdate($ruleSetId);

            if ($ruleSet->approval_status !== 'draft') {
                throw ValidationException::withMessages(['compliance_rule_set' => ['Only draft rule sets can be approved.']]);
            }

            if ($ruleSet->rules->isEmpty()) {
                throw ValidationException::withMessages(['rules' => ['A rule set must contain at least one rule before approval.']]);
            }

            if ($ruleSet->jurisdiction === 'XK' && $ruleSet->domain === 'vat') {
                $this->validateVatConfiguration($ruleSet);
            }

            if ($ruleSet->jurisdiction === 'XK' && $ruleSet->domain === 'tax_calendar') {
                $problems = $this->taxCalendarGenerator->problems($ruleSet->rules->firstWhere('rule_key', 'deadlines')?->configuration['deadlines'] ?? null);

                if ($problems !== []) {
                    throw ValidationException::withMessages(['rules' => $problems]);
                }
            }

            if ($ruleSet->jurisdiction === 'XK' && $ruleSet->domain === 'payroll') {
                $problems = $this->payrollCalculator->problems([
                    'pension_contributions' => $ruleSet->rules->firstWhere('rule_key', 'pension_contributions')?->configuration,
                    'income_tax' => $ruleSet->rules->firstWhere('rule_key', 'income_tax')?->configuration,
                ]);

                if ($problems !== []) {
                    throw ValidationException::withMessages(['rules' => $problems]);
                }
            }

            $this->ruleSetRepository->lockDomain($ruleSet->jurisdiction, $ruleSet->domain);

            if ($this->ruleSetRepository->hasApprovedOverlap($ruleSet)) {
                throw ValidationException::withMessages(['valid_from' => ['An approved rule set already covers part of this effective date range.']]);
            }

            $oldValues = $this->auditValues($ruleSet);
            $ruleSet->forceFill([
                'approval_status' => 'approved',
                'approved_by' => $user->getKey(),
                'approved_at' => now(),
            ])->save();
            $ruleSet->refresh()->load(['rules', 'creator', 'approver']);
            $this->audit($user, $ruleSet, 'compliance_rule_set.approved', $oldValues, $this->auditValues($ruleSet));

            return $ruleSet;
        });
    }

    /** @param list<array<string, mixed>> $rules
     * @return list<array<string, mixed>>
     */
    private function validatedRuleRows(array $rules): array
    {
        return array_map(fn (array $rule): array => [
            'rule_key' => $rule['rule_key'],
            'configuration' => $rule['configuration'],
            'description' => $rule['description'] ?? null,
        ], $rules);
    }

    /** @return array<string, mixed> */
    private function auditValues(ComplianceRuleSet $ruleSet): array
    {
        return [
            'jurisdiction' => $ruleSet->jurisdiction,
            'domain' => $ruleSet->domain,
            'version' => $ruleSet->version,
            'valid_from' => $ruleSet->valid_from->toDateString(),
            'valid_to' => $ruleSet->valid_to?->toDateString(),
            'source_title' => $ruleSet->source_title,
            'source_url' => $ruleSet->source_url,
            'approval_status' => $ruleSet->approval_status,
            'rules' => $ruleSet->rules->map(fn ($rule): array => [
                'rule_key' => $rule->rule_key,
                'configuration' => $rule->configuration,
                'description' => $rule->description,
            ])->all(),
        ];
    }

    private function validateVatConfiguration(ComplianceRuleSet $ruleSet): void
    {
        $configuration = $ruleSet->rules->firstWhere('rule_key', 'vat_codes')?->configuration;
        $codes = $configuration['codes'] ?? null;

        if (! is_array($codes) || $codes === []) {
            throw ValidationException::withMessages(['rules' => ['A Kosovo VAT rule set must include a vat_codes rule with a non-empty codes list.']]);
        }

        $seenCodes = [];

        foreach ($codes as $code) {
            if (! is_array($code)
                || ! isset($code['code'], $code['label'], $code['rate_percent'], $code['treatment'])
                || ! is_string($code['code'])
                || preg_match('/^[a-zA-Z0-9_-]{1,40}$/', $code['code']) !== 1
                || ! is_string($code['label'])
                || ! is_numeric($code['rate_percent'])
                || ! in_array($code['treatment'], ['taxable', 'exempt', 'zero_rated', 'non_vat'], true)) {
                throw ValidationException::withMessages(['rules' => ['VAT codes need a simple code, label, numeric rate_percent, and supported treatment.']]);
            }

            $rate = (string) $code['rate_percent'];

            if (preg_match('/^(\d{1,3})(?:\.(\d{1,4}))?$/', $rate, $matches) !== 1) {
                throw ValidationException::withMessages(['rules' => ['VAT rates must be non-negative percentages with at most four decimal places.']]);
            }

            $scaledRate = ((int) $matches[1] * 10_000) + (int) str_pad($matches[2] ?? '', 4, '0');

            if ($scaledRate > 1_000_000 || (in_array($code['treatment'], ['exempt', 'zero_rated', 'non_vat'], true) && $scaledRate !== 0)) {
                throw ValidationException::withMessages(['rules' => ['VAT rates must not exceed one hundred percent; exempt, zero-rated, and non-VAT codes must use zero.']]);
            }

            if (isset($seenCodes[$code['code']])) {
                throw ValidationException::withMessages(['rules' => ['VAT code values must be unique.']]);
            }

            $seenCodes[$code['code']] = true;
        }

        $threshold = $ruleSet->rules->firstWhere('rule_key', 'registration_threshold')?->configuration;

        if ($threshold !== null && (! isset($threshold['amount']) || ! is_numeric($threshold['amount']) || (float) $threshold['amount'] <= 0
            || (isset($threshold['warning_percent']) && (! is_numeric($threshold['warning_percent']) || (float) $threshold['warning_percent'] <= 0 || (float) $threshold['warning_percent'] > 100)))) {
            throw ValidationException::withMessages(['rules' => ['A registration_threshold rule needs a positive amount and an optional warning_percent between 1 and 100.']]);
        }
    }

    /** @param array<string, mixed>|null $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    private function audit(User $user, ComplianceRuleSet $ruleSet, string $action, ?array $oldValues, ?array $newValues): void
    {
        AuditLog::query()->create([
            'user_id' => $user->getKey(),
            'action' => $action,
            'subject_type' => ComplianceRuleSet::class,
            'subject_id' => $ruleSet->getKey(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
        ]);
    }
}
