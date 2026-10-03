<?php

namespace App\Repositories\Contracts;

use App\Models\Business;
use App\Models\BusinessTask;
use Illuminate\Database\Eloquent\Collection;

interface TaskRepository
{
    /** @return Collection<int, BusinessTask> */
    public function listForBusiness(Business $business, ?string $from, ?string $to, ?string $status): Collection;

    public function findForUpdate(Business $business, int $taskId): BusinessTask;

    /** @param array<string, mixed> $attributes */
    public function create(Business $business, array $attributes): BusinessTask;

    public function obligationExists(Business $business, string $obligationType, string $periodLabel): bool;

    public function save(BusinessTask $task): BusinessTask;

    /**
     * Open tasks due on or before the given date, across the given businesses.
     *
     * @param  list<int>  $businessIds
     * @return Collection<int, BusinessTask>
     */
    public function openDueBy(array $businessIds, string $date): Collection;
}
