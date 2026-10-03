<?php

namespace App\Repositories\Contracts;

use App\Models\Business;
use App\Models\DepreciationRun;
use App\Models\FixedAsset;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

interface FixedAssetRepository
{
    /** @return Collection<int, FixedAsset> */
    public function listAssets(Business $business): Collection;

    public function findAsset(Business $business, int $assetId): FixedAsset;

    public function findAssetForUpdate(Business $business, int $assetId): FixedAsset;

    public function assetCodeExists(Business $business, string $assetCode): bool;

    /** @param array<string, mixed> $attributes */
    public function createAsset(Business $business, array $attributes): FixedAsset;

    /** @param array<string, mixed> $details */
    public function recordEvent(FixedAsset $asset, string $type, string $occurredOn, array $details, ?int $journalEntryId, int $userId): void;

    /**
     * Posted depreciation per asset, in cents, keyed by asset id.
     *
     * @param  list<int>  $assetIds
     * @return array<int, int>
     */
    public function postedDepreciationCents(Business $business, array $assetIds): array;

    /** @return Collection<int, DepreciationRun> */
    public function listRuns(Business $business): Collection;

    public function latestRun(Business $business): ?DepreciationRun;

    public function hasDraftRun(Business $business): bool;

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $lines
     */
    public function createRun(Business $business, array $attributes, array $lines): DepreciationRun;

    public function findRun(Business $business, int $runId): DepreciationRun;

    public function findRunForUpdate(Business $business, int $runId): DepreciationRun;

    public function markRunApproved(DepreciationRun $run, JournalEntry $journalEntry, User $user): DepreciationRun;

    public function deleteRun(DepreciationRun $run): void;
}
