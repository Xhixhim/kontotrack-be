<?php

namespace App\Repositories\Eloquent;

use App\Models\Business;
use App\Models\BusinessTask;
use App\Repositories\Contracts\TaskRepository;
use Illuminate\Database\Eloquent\Collection;

class EloquentTaskRepository implements TaskRepository
{
    /** @return Collection<int, BusinessTask> */
    public function listForBusiness(Business $business, ?string $from, ?string $to, ?string $status): Collection
    {
        return $business->tasks()
            ->when($from !== null, fn ($query) => $query->whereDate('due_on', '>=', $from))
            ->when($to !== null, fn ($query) => $query->whereDate('due_on', '<=', $to))
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->with(['assignee:id,name', 'document:id,original_name'])
            ->orderBy('due_on')
            ->orderBy('id')
            ->get();
    }

    public function findForUpdate(Business $business, int $taskId): BusinessTask
    {
        return $business->tasks()->whereKey($taskId)->lockForUpdate()->firstOrFail();
    }

    /** @param array<string, mixed> $attributes */
    public function create(Business $business, array $attributes): BusinessTask
    {
        return $business->tasks()->create($attributes);
    }

    public function obligationExists(Business $business, string $obligationType, string $periodLabel): bool
    {
        return $business->tasks()->where('obligation_type', $obligationType)->where('period_label', $periodLabel)->exists();
    }

    public function save(BusinessTask $task): BusinessTask
    {
        $task->save();

        return $task->load(['assignee:id,name', 'document:id,original_name']);
    }

    /**
     * @param  list<int>  $businessIds
     * @return Collection<int, BusinessTask>
     */
    public function openDueBy(array $businessIds, string $date): Collection
    {
        return BusinessTask::query()
            ->whereIn('business_id', $businessIds)
            ->where('status', 'open')
            ->whereDate('due_on', '<=', $date)
            ->with('business:id,legal_name,trade_name')
            ->orderBy('due_on')
            ->get();
    }
}
