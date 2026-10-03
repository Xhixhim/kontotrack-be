<?php

namespace App\Repositories\Eloquent;

use App\Models\Business;
use App\Models\DepreciationRun;
use App\Models\FixedAsset;
use App\Models\JournalEntry;
use App\Models\User;
use App\Repositories\Contracts\FixedAssetRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class EloquentFixedAssetRepository implements FixedAssetRepository
{
    /** @return Collection<int, FixedAsset> */
    public function listAssets(Business $business): Collection
    {
        return $business->fixedAssets()->orderBy('asset_code')->get();
    }

    public function findAsset(Business $business, int $assetId): FixedAsset
    {
        return $business->fixedAssets()
            ->whereKey($assetId)
            ->with(['events' => fn ($query) => $query->with('user:id,name')->orderBy('occurred_on')->orderBy('id'), 'depreciationLines.run'])
            ->firstOrFail();
    }

    public function findAssetForUpdate(Business $business, int $assetId): FixedAsset
    {
        return $business->fixedAssets()->whereKey($assetId)->lockForUpdate()->firstOrFail();
    }

    public function assetCodeExists(Business $business, string $assetCode): bool
    {
        return $business->fixedAssets()->where('asset_code', $assetCode)->exists();
    }

    /** @param array<string, mixed> $attributes */
    public function createAsset(Business $business, array $attributes): FixedAsset
    {
        return $business->fixedAssets()->create($attributes);
    }

    /** @param array<string, mixed> $details */
    public function recordEvent(FixedAsset $asset, string $type, string $occurredOn, array $details, ?int $journalEntryId, int $userId): void
    {
        $asset->events()->create([
            'business_id' => $asset->business_id,
            'event_type' => $type,
            'occurred_on' => $occurredOn,
            'details' => $details,
            'journal_entry_id' => $journalEntryId,
            'user_id' => $userId,
        ]);
    }

    /**
     * @param  list<int>  $assetIds
     * @return array<int, int>
     */
    public function postedDepreciationCents(Business $business, array $assetIds): array
    {
        if ($assetIds === []) {
            return [];
        }

        return DB::table('depreciation_lines as lines')
            ->join('depreciation_runs as runs', 'runs.id', '=', 'lines.depreciation_run_id')
            ->where('lines.business_id', $business->getKey())
            ->where('runs.status', 'approved')
            ->whereIn('lines.fixed_asset_id', $assetIds)
            ->groupBy('lines.fixed_asset_id')
            ->selectRaw('lines.fixed_asset_id, SUM(ROUND(lines.amount * 100)) AS total_cents')
            ->pluck('total_cents', 'fixed_asset_id')
            ->map(fn ($cents): int => (int) $cents)
            ->all();
    }

    /** @return Collection<int, DepreciationRun> */
    public function listRuns(Business $business): Collection
    {
        return $business->depreciationRuns()->orderByDesc('period_year')->orderByDesc('period_month')->get();
    }

    public function latestRun(Business $business): ?DepreciationRun
    {
        return $business->depreciationRuns()->orderByDesc('period_year')->orderByDesc('period_month')->first();
    }

    public function hasDraftRun(Business $business): bool
    {
        return $business->depreciationRuns()->where('status', 'draft')->exists();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $lines
     */
    public function createRun(Business $business, array $attributes, array $lines): DepreciationRun
    {
        $run = $business->depreciationRuns()->create($attributes);
        $run->lines()->createMany($lines);

        return $run->load('lines.fixedAsset');
    }

    public function findRun(Business $business, int $runId): DepreciationRun
    {
        return $business->depreciationRuns()->whereKey($runId)->with('lines.fixedAsset')->firstOrFail();
    }

    public function findRunForUpdate(Business $business, int $runId): DepreciationRun
    {
        return $business->depreciationRuns()->whereKey($runId)->lockForUpdate()->with('lines.fixedAsset')->firstOrFail();
    }

    public function markRunApproved(DepreciationRun $run, JournalEntry $journalEntry, User $user): DepreciationRun
    {
        $run->forceFill([
            'status' => 'approved',
            'journal_entry_id' => $journalEntry->getKey(),
            'approved_by' => $user->getKey(),
            'approved_at' => now(),
        ])->save();

        return $run->refresh()->load('lines.fixedAsset');
    }

    public function deleteRun(DepreciationRun $run): void
    {
        $run->delete();
    }
}
