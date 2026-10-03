<?php

namespace App\Repositories\Eloquent;

use App\Models\ComplianceRuleSet;
use App\Repositories\Contracts\ComplianceRuleSetRepository;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class EloquentComplianceRuleSetRepository implements ComplianceRuleSetRepository
{
    /** @return LengthAwarePaginator<int, ComplianceRuleSet> */
    public function listForUser(bool $includeDrafts, ?string $jurisdiction, ?string $domain): LengthAwarePaginator
    {
        return ComplianceRuleSet::query()
            ->when(! $includeDrafts, fn ($query) => $query->where('approval_status', 'approved'))
            ->when($jurisdiction !== null, fn ($query) => $query->where('jurisdiction', $jurisdiction))
            ->when($domain !== null, fn ($query) => $query->where('domain', $domain))
            ->with(['rules', 'creator', 'approver'])
            ->orderByDesc('valid_from')
            ->orderByDesc('id')
            ->paginate(50);
    }

    public function findForUpdate(int $ruleSetId): ComplianceRuleSet
    {
        return ComplianceRuleSet::query()->whereKey($ruleSetId)->lockForUpdate()->with('rules')->firstOrFail();
    }

    public function findEffective(string $jurisdiction, string $domain, string $date): ?ComplianceRuleSet
    {
        return ComplianceRuleSet::query()
            ->where('jurisdiction', $jurisdiction)
            ->where('domain', $domain)
            ->where('approval_status', 'approved')
            ->whereDate('valid_from', '<=', $date)
            ->where(fn ($query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date))
            ->with(['rules', 'approver'])
            ->first();
    }

    /** @param array<string, mixed> $attributes
     * @param  list<array<string, mixed>>  $rules
     */
    public function createDraft(array $attributes, array $rules): ComplianceRuleSet
    {
        $ruleSet = ComplianceRuleSet::query()->create($attributes);
        $ruleSet->rules()->createMany($rules);

        return $ruleSet->load(['rules', 'creator', 'approver']);
    }

    /** @param array<string, mixed> $attributes
     * @param  list<array<string, mixed>>  $rules
     */
    public function updateDraft(ComplianceRuleSet $ruleSet, array $attributes, array $rules): ComplianceRuleSet
    {
        $ruleSet->update($attributes);
        $ruleSet->rules()->delete();
        $ruleSet->rules()->createMany($rules);

        return $ruleSet->refresh()->load(['rules', 'creator', 'approver']);
    }

    public function lockDomain(string $jurisdiction, string $domain): void
    {
        DB::table('compliance_rule_domains')->insertOrIgnore([
            'jurisdiction' => $jurisdiction,
            'domain' => $domain,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('compliance_rule_domains')
            ->where('jurisdiction', $jurisdiction)
            ->where('domain', $domain)
            ->lockForUpdate()
            ->firstOrFail();
    }

    public function hasApprovedOverlap(ComplianceRuleSet $ruleSet): bool
    {
        return ComplianceRuleSet::query()
            ->where('jurisdiction', $ruleSet->jurisdiction)
            ->where('domain', $ruleSet->domain)
            ->where('approval_status', 'approved')
            ->where('id', '<>', $ruleSet->getKey())
            ->when($ruleSet->valid_to !== null, fn ($query) => $query->whereDate('valid_from', '<=', $ruleSet->valid_to->toDateString()))
            ->where(fn ($query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $ruleSet->valid_from->toDateString()))
            ->exists();
    }
}
