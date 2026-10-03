<?php

namespace App\Services\Assets;

use App\Models\Business;
use App\Models\DepreciationRun;
use App\Models\FixedAsset;
use App\Models\User;
use App\Repositories\Contracts\FixedAssetRepository;
use App\Services\Accounting\JournalEntryService;
use App\Services\Accounting\PostingAccountService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FixedAssetService
{
    public function __construct(
        private FixedAssetRepository $assetRepository,
        private DepreciationCalculator $calculator,
        private JournalEntryService $journalEntryService,
        private PostingAccountService $postingAccountService,
    ) {}

    /**
     * Assets with their accumulated depreciation and current book value attached.
     *
     * @return Collection<int, FixedAsset>
     */
    public function listAssets(Business $business): Collection
    {
        $assets = $this->assetRepository->listAssets($business);
        $this->attachBookValues($business, $assets);

        return $assets;
    }

    public function findAsset(Business $business, int $assetId): FixedAsset
    {
        $asset = $this->assetRepository->findAsset($business, $assetId);
        $this->attachBookValues($business, new Collection([$asset]));

        return $asset;
    }

    /** @param array<string, mixed> $attributes */
    public function createAsset(Business $business, User $user, array $attributes): FixedAsset
    {
        return DB::transaction(function () use ($business, $user, $attributes): FixedAsset {
            if ($this->assetRepository->assetCodeExists($business, $attributes['asset_code'])) {
                throw ValidationException::withMessages(['asset_code' => ['This asset code is already used in this business.']]);
            }

            $costCents = $this->toCents((string) $attributes['cost']);
            $residualCents = $this->toCents((string) ($attributes['residual_value'] ?? '0'));
            $openingCents = $this->toCents((string) ($attributes['opening_accumulated_depreciation'] ?? '0'));

            if ($residualCents >= $costCents) {
                throw ValidationException::withMessages(['residual_value' => ['The residual value must be lower than the cost.']]);
            }

            if ($openingCents > $costCents - $residualCents) {
                throw ValidationException::withMessages(['opening_accumulated_depreciation' => ['Opening depreciation cannot exceed the cost minus the residual value.']]);
            }

            if ($attributes['depreciation_method'] === 'straight_line' && empty($attributes['useful_life_months'])) {
                throw ValidationException::withMessages(['useful_life_months' => ['Straight-line depreciation needs a useful life in months.']]);
            }

            if ($attributes['depreciation_method'] === 'declining_balance' && empty($attributes['annual_rate_percent'])) {
                throw ValidationException::withMessages(['annual_rate_percent' => ['Declining-balance depreciation needs an annual rate.']]);
            }

            if (isset($attributes['document_id']) && ! $business->documents()->whereKey($attributes['document_id'])->exists()) {
                throw ValidationException::withMessages(['document_id' => ['Choose a document that belongs to this business.']]);
            }

            $attributes['created_by'] = $user->getKey();
            $attributes['status'] = 'active';
            $asset = $this->assetRepository->createAsset($business, $attributes);
            $this->assetRepository->recordEvent($asset, 'acquired', $asset->acquired_on->toDateString(), [
                'cost' => $asset->cost,
                'location' => $asset->location,
            ], null, (int) $user->getKey());
            $business->auditLogs()->create([
                'user_id' => $user->getKey(),
                'action' => 'fixed_asset.created',
                'subject_type' => FixedAsset::class,
                'subject_id' => $asset->getKey(),
                'new_values' => $asset->only(['asset_code', 'name', 'category', 'cost', 'depreciation_method', 'useful_life_months', 'annual_rate_percent']),
            ]);

            $asset->load(['events' => fn ($query) => $query->with('user:id,name')->orderBy('occurred_on')->orderBy('id'), 'depreciationLines.run']);
            $this->attachBookValues($business, new Collection([$asset]));

            return $asset;
        });
    }

    /** @param array{location: string, transferred_on: string, note?: string|null} $attributes */
    public function transferAsset(Business $business, User $user, int $assetId, array $attributes): FixedAsset
    {
        return DB::transaction(function () use ($business, $user, $assetId, $attributes): FixedAsset {
            $asset = $this->assetRepository->findAssetForUpdate($business, $assetId);

            if ($asset->status !== 'active') {
                throw ValidationException::withMessages(['fixed_asset' => ['Only active assets can be transferred.']]);
            }

            $oldLocation = $asset->location;
            $asset->forceFill(['location' => $attributes['location']])->save();
            $this->assetRepository->recordEvent($asset, 'transferred', $attributes['transferred_on'], [
                'from' => $oldLocation,
                'to' => $attributes['location'],
                'note' => $attributes['note'] ?? null,
            ], null, (int) $user->getKey());
            $business->auditLogs()->create([
                'user_id' => $user->getKey(),
                'action' => 'fixed_asset.transferred',
                'subject_type' => FixedAsset::class,
                'subject_id' => $asset->getKey(),
                'old_values' => ['location' => $oldLocation],
                'new_values' => ['location' => $attributes['location']],
            ]);

            return $this->findAsset($business, $assetId);
        });
    }

    /**
     * Remove a sold or written-off asset from the books: the remaining book value is posted as a disposal loss.
     * Any sale proceeds are recorded separately with a sales invoice.
     *
     * @param  array{disposed_on: string, disposal_type: string, disposal_proceeds?: string|null, note?: string|null}  $attributes
     */
    public function disposeAsset(Business $business, User $user, int $assetId, array $attributes): FixedAsset
    {
        return DB::transaction(function () use ($business, $user, $assetId, $attributes): FixedAsset {
            $asset = $this->assetRepository->findAssetForUpdate($business, $assetId);

            if ($asset->status !== 'active') {
                throw ValidationException::withMessages(['fixed_asset' => ['This asset has already been disposed of.']]);
            }

            if ($attributes['disposed_on'] < $asset->acquired_on->toDateString()) {
                throw ValidationException::withMessages(['disposed_on' => ['The disposal date cannot be before the acquisition date.']]);
            }

            if ($this->assetRepository->hasDraftRun($business)) {
                throw ValidationException::withMessages(['fixed_asset' => ['Approve or delete the draft depreciation run before disposing of an asset.']]);
            }

            $periodId = $this->journalEntryService->findOpenPeriodIdForDate($business, $attributes['disposed_on']);

            if ($periodId === null) {
                throw ValidationException::withMessages(['disposed_on' => ['Open an accounting period that contains the disposal date.']]);
            }

            $costCents = $this->toCents((string) $asset->cost);
            $accumulatedCents = $this->accumulatedCents($business, $asset);
            $bookValueCents = $costCents - $accumulatedCents;
            $purposes = ['fixed_assets'];

            if ($accumulatedCents > 0) {
                $purposes[] = 'accumulated_depreciation';
            }

            if ($bookValueCents > 0) {
                $purposes[] = 'asset_disposal_loss';
            }

            $accounts = $this->postingAccountService->requireAccounts($business, $purposes);
            $lines = array_values(array_filter([
                $accumulatedCents > 0 ? $this->line($accounts['accumulated_depreciation']->getKey(), "Accumulated depreciation {$asset->asset_code}", $accumulatedCents, 0) : null,
                $bookValueCents > 0 ? $this->line($accounts['asset_disposal_loss']->getKey(), "Book value written off {$asset->asset_code}", $bookValueCents, 0) : null,
                $this->line($accounts['fixed_assets']->getKey(), "Disposal of {$asset->asset_code} {$asset->name}", 0, $costCents),
            ]));
            $entry = $this->journalEntryService->createDraft($business, $user, [
                'accounting_period_id' => $periodId,
                'entry_date' => $attributes['disposed_on'],
                'reference' => mb_substr("DISPOSAL-{$asset->asset_code}", 0, 100),
                'description' => mb_substr("Disposal of fixed asset {$asset->asset_code} {$asset->name}", 0, 255),
                'document_id' => $asset->document_id,
                'lines' => $lines,
            ]);
            $entry = $this->journalEntryService->post($business, $user, (int) $entry->getKey());
            $asset->forceFill([
                'status' => 'disposed',
                'disposed_on' => $attributes['disposed_on'],
                'disposal_proceeds' => $attributes['disposal_proceeds'] ?? null,
            ])->save();
            $details = [
                'disposal_type' => $attributes['disposal_type'],
                'book_value' => $this->fromCents($bookValueCents),
                'proceeds' => $attributes['disposal_proceeds'] ?? null,
                'note' => $attributes['note'] ?? null,
            ];
            $this->assetRepository->recordEvent($asset, $attributes['disposal_type'], $attributes['disposed_on'], $details, (int) $entry->getKey(), (int) $user->getKey());
            $business->auditLogs()->create([
                'user_id' => $user->getKey(),
                'action' => 'fixed_asset.disposed',
                'subject_type' => FixedAsset::class,
                'subject_id' => $asset->getKey(),
                'old_values' => ['status' => 'active'],
                'new_values' => ['status' => 'disposed', 'journal_entry_id' => $entry->getKey(), ...$details],
            ]);

            return $this->findAsset($business, $assetId);
        });
    }

    /** @return Collection<int, DepreciationRun> */
    public function listRuns(Business $business): Collection
    {
        return $this->assetRepository->listRuns($business);
    }

    public function findRun(Business $business, int $runId): DepreciationRun
    {
        return $this->assetRepository->findRun($business, $runId);
    }

    /**
     * Calculate one month of depreciation for every asset in use. Runs must be created in month order.
     */
    public function createDraftRun(Business $business, User $user, int $year, int $month): DepreciationRun
    {
        return DB::transaction(function () use ($business, $user, $year, $month): DepreciationRun {
            $latest = $this->assetRepository->latestRun($business);

            if ($latest !== null && ($latest->period_year * 12 + $latest->period_month) >= ($year * 12 + $month)) {
                throw ValidationException::withMessages(['period_month' => ['Depreciation runs must follow the latest run ('.sprintf('%04d-%02d', $latest->period_year, $latest->period_month).').']]);
            }

            if ($latest !== null && $latest->status === 'draft') {
                throw ValidationException::withMessages(['period_month' => ['Approve or delete the draft depreciation run first.']]);
            }

            $monthStart = Carbon::create($year, $month, 1);
            $assets = $this->assetRepository->listAssets($business)->filter(fn (FixedAsset $asset): bool => $asset->status === 'active'
                && $asset->acquired_on->copy()->startOfMonth()->lt($monthStart));
            $posted = $this->assetRepository->postedDepreciationCents($business, $assets->pluck('id')->map(fn ($id): int => (int) $id)->values()->all());
            $lines = [];
            $totalCents = 0;

            foreach ($assets as $asset) {
                $costCents = $this->toCents((string) $asset->cost);
                $bookValueCents = $costCents - $this->toCents((string) $asset->opening_accumulated_depreciation) - ($posted[$asset->id] ?? 0);
                $amountCents = $this->calculator->monthlyAmount(
                    $asset->depreciation_method,
                    $costCents,
                    $this->toCents((string) $asset->residual_value),
                    $bookValueCents,
                    $asset->useful_life_months,
                    $asset->annual_rate_percent === null ? null : (string) $asset->annual_rate_percent,
                );

                if ($amountCents === 0) {
                    continue;
                }

                $totalCents += $amountCents;
                $lines[] = [
                    'business_id' => $business->getKey(),
                    'fixed_asset_id' => $asset->getKey(),
                    'opening_book_value' => $this->fromCents($bookValueCents),
                    'amount' => $this->fromCents($amountCents),
                    'closing_book_value' => $this->fromCents($bookValueCents - $amountCents),
                ];
            }

            if ($lines === []) {
                throw ValidationException::withMessages(['period_month' => ['No asset needs depreciation for this month.']]);
            }

            $run = $this->assetRepository->createRun($business, [
                'period_year' => $year,
                'period_month' => $month,
                'status' => 'draft',
                'total' => $this->fromCents($totalCents),
                'created_by' => $user->getKey(),
            ], $lines);
            $business->auditLogs()->create([
                'user_id' => $user->getKey(),
                'action' => 'depreciation_run.draft_created',
                'subject_type' => DepreciationRun::class,
                'subject_id' => $run->getKey(),
                'new_values' => ['period' => sprintf('%04d-%02d', $year, $month), 'assets' => count($lines), 'total' => $run->total],
            ]);

            return $run;
        });
    }

    public function approveRun(Business $business, User $user, int $runId): DepreciationRun
    {
        return DB::transaction(function () use ($business, $user, $runId): DepreciationRun {
            $run = $this->assetRepository->findRunForUpdate($business, $runId);

            if ($run->status !== 'draft') {
                throw ValidationException::withMessages(['depreciation_run' => ['Only draft depreciation runs can be approved.']]);
            }

            $entryDate = Carbon::create($run->period_year, $run->period_month, 1)->endOfMonth()->toDateString();
            $periodId = $this->journalEntryService->findOpenPeriodIdForDate($business, $entryDate);

            if ($periodId === null) {
                throw ValidationException::withMessages(['depreciation_run' => ['Open an accounting period that contains the last day of the depreciation month.']]);
            }

            $accounts = $this->postingAccountService->requireAccounts($business, ['depreciation_expense', 'accumulated_depreciation']);
            $period = sprintf('%04d-%02d', $run->period_year, $run->period_month);
            $totalCents = $this->toCents((string) $run->total);
            $entry = $this->journalEntryService->createDraft($business, $user, [
                'accounting_period_id' => $periodId,
                'entry_date' => $entryDate,
                'reference' => "DEPRECIATION-{$period}",
                'description' => "Depreciation {$period}",
                'lines' => [
                    $this->line($accounts['depreciation_expense']->getKey(), "Depreciation expense {$period}", $totalCents, 0),
                    $this->line($accounts['accumulated_depreciation']->getKey(), "Accumulated depreciation {$period}", 0, $totalCents),
                ],
            ]);
            $entry = $this->journalEntryService->post($business, $user, (int) $entry->getKey());
            $approved = $this->assetRepository->markRunApproved($run, $entry, $user);
            $business->auditLogs()->create([
                'user_id' => $user->getKey(),
                'action' => 'depreciation_run.approved',
                'subject_type' => DepreciationRun::class,
                'subject_id' => $run->getKey(),
                'old_values' => ['status' => 'draft'],
                'new_values' => ['status' => 'approved', 'journal_entry_id' => $entry->getKey()],
            ]);

            return $approved;
        });
    }

    public function deleteDraftRun(Business $business, User $user, int $runId): void
    {
        DB::transaction(function () use ($business, $user, $runId): void {
            $run = $this->assetRepository->findRunForUpdate($business, $runId);

            if ($run->status !== 'draft') {
                throw ValidationException::withMessages(['depreciation_run' => ['Only draft depreciation runs can be deleted.']]);
            }

            $business->auditLogs()->create([
                'user_id' => $user->getKey(),
                'action' => 'depreciation_run.draft_deleted',
                'subject_type' => DepreciationRun::class,
                'subject_id' => $run->getKey(),
                'old_values' => ['period' => sprintf('%04d-%02d', $run->period_year, $run->period_month), 'total' => $run->total],
            ]);
            $this->assetRepository->deleteRun($run);
        });
    }

    /** @param Collection<int, FixedAsset> $assets */
    private function attachBookValues(Business $business, Collection $assets): void
    {
        $posted = $this->assetRepository->postedDepreciationCents($business, $assets->pluck('id')->map(fn ($id): int => (int) $id)->values()->all());

        foreach ($assets as $asset) {
            $accumulatedCents = $this->toCents((string) $asset->opening_accumulated_depreciation) + ($posted[$asset->id] ?? 0);
            $asset->setAttribute('accumulated_depreciation', $this->fromCents($accumulatedCents));
            $asset->setAttribute('book_value', $this->fromCents($asset->status === 'disposed' ? 0 : $this->toCents((string) $asset->cost) - $accumulatedCents));
        }
    }

    private function accumulatedCents(Business $business, FixedAsset $asset): int
    {
        return $this->toCents((string) $asset->opening_accumulated_depreciation)
            + ($this->assetRepository->postedDepreciationCents($business, [(int) $asset->getKey()])[$asset->getKey()] ?? 0);
    }

    /** @return array{account_id: int, counterparty_id: null, description: string, debit: string, credit: string} */
    private function line(int $accountId, string $description, int $debitCents, int $creditCents): array
    {
        return [
            'account_id' => $accountId,
            'counterparty_id' => null,
            'description' => mb_substr($description, 0, 255),
            'debit' => $this->fromCents($debitCents),
            'credit' => $this->fromCents($creditCents),
        ];
    }

    private function toCents(string $amount): int
    {
        $negative = str_starts_with($amount, '-');
        $amount = ltrim($amount, '+-');
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        $cents = ((int) $whole * 100) + (int) substr(str_pad($fraction, 2, '0'), 0, 2);

        return $negative ? -$cents : $cents;
    }

    private function fromCents(int $amount): string
    {
        $sign = $amount < 0 ? '-' : '';
        $absoluteAmount = abs($amount);

        return $sign.intdiv($absoluteAmount, 100).'.'.str_pad((string) ($absoluteAmount % 100), 2, '0', STR_PAD_LEFT);
    }
}
