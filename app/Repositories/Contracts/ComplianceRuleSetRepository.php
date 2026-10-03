<?php

namespace App\Repositories\Contracts;

use App\Models\ComplianceRuleSet;
use Illuminate\Pagination\LengthAwarePaginator;

interface ComplianceRuleSetRepository
{
    /** @return LengthAwarePaginator<int, ComplianceRuleSet> */
    public function listForUser(bool $includeDrafts, ?string $jurisdiction, ?string $domain): LengthAwarePaginator;

    public function findForUpdate(int $ruleSetId): ComplianceRuleSet;

    public function findEffective(string $jurisdiction, string $domain, string $date): ?ComplianceRuleSet;

    /** @param array<string, mixed> $attributes
     * @param  list<array<string, mixed>>  $rules
     */
    public function createDraft(array $attributes, array $rules): ComplianceRuleSet;

    /** @param array<string, mixed> $attributes
     * @param  list<array<string, mixed>>  $rules
     */
    public function updateDraft(ComplianceRuleSet $ruleSet, array $attributes, array $rules): ComplianceRuleSet;

    public function lockDomain(string $jurisdiction, string $domain): void;

    public function hasApprovedOverlap(ComplianceRuleSet $ruleSet): bool;
}
