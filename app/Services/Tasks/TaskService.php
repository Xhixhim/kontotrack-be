<?php

namespace App\Services\Tasks;

use App\Models\Business;
use App\Models\BusinessTask;
use App\Models\User;
use App\Repositories\Contracts\ComplianceRuleSetRepository;
use App\Repositories\Contracts\TaskRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaskService
{
    public function __construct(
        private TaskRepository $taskRepository,
        private ComplianceRuleSetRepository $complianceRuleSetRepository,
        private TaxCalendarGenerator $calendarGenerator,
    ) {}

    /** @return Collection<int, BusinessTask> */
    public function list(Business $business, ?string $from, ?string $to, ?string $status): Collection
    {
        return $this->taskRepository->listForBusiness($business, $from, $to, $status);
    }

    /** @param array{title: string, due_on: string, notes?: string|null, assigned_to?: int|null} $attributes */
    public function createTask(Business $business, User $user, array $attributes): BusinessTask
    {
        return DB::transaction(function () use ($business, $user, $attributes): BusinessTask {
            $this->assertAssigneeIsMember($business, $attributes['assigned_to'] ?? null);
            $task = $this->taskRepository->create($business, [
                'kind' => 'task',
                'title' => $attributes['title'],
                'notes' => $attributes['notes'] ?? null,
                'due_on' => $attributes['due_on'],
                'assigned_to' => $attributes['assigned_to'] ?? null,
                'status' => 'open',
                'created_by' => $user->getKey(),
            ]);
            $this->audit($business, $user, 'task.created', $task, null, $task->only(['title', 'due_on', 'assigned_to']));

            return $task->load(['assignee:id,name', 'document:id,original_name']);
        });
    }

    /** @param array<string, mixed> $attributes */
    public function updateTask(Business $business, User $user, int $taskId, array $attributes): BusinessTask
    {
        return DB::transaction(function () use ($business, $user, $taskId, $attributes): BusinessTask {
            $task = $this->taskRepository->findForUpdate($business, $taskId);

            if ($task->status !== 'open') {
                throw ValidationException::withMessages(['task' => ['Completed tasks cannot be changed.']]);
            }

            if (array_key_exists('assigned_to', $attributes)) {
                $this->assertAssigneeIsMember($business, $attributes['assigned_to']);
            }

            $task->fill($attributes);
            $changed = array_keys($task->getDirty());

            if ($changed === []) {
                return $task->load(['assignee:id,name', 'document:id,original_name']);
            }

            $old = [];
            $new = [];

            foreach ($changed as $attribute) {
                $old[$attribute] = $this->auditValue($task->getOriginal($attribute));
                $new[$attribute] = $this->auditValue($task->getAttribute($attribute));
            }

            $task = $this->taskRepository->save($task);
            $this->audit($business, $user, 'task.updated', $task, $old, $new);

            return $task;
        });
    }

    /**
     * Create the filing deadlines for a year from the approved tax calendar. Existing ones are left untouched.
     *
     * @return array{created: int, rule_version: string}
     */
    public function generateTaxCalendar(Business $business, User $user, int $year): array
    {
        return DB::transaction(function () use ($business, $user, $year): array {
            $ruleSet = $this->complianceRuleSetRepository->findEffective('XK', 'tax_calendar', "{$year}-01-01");

            if ($ruleSet === null) {
                throw ValidationException::withMessages(['year' => ['No approved tax calendar is effective for this year.']]);
            }

            $deadlines = $ruleSet->rules->firstWhere('rule_key', 'deadlines')?->configuration['deadlines'] ?? null;
            $problems = $this->calendarGenerator->problems($deadlines);

            if ($problems !== []) {
                throw ValidationException::withMessages(['compliance_rule_set' => $problems]);
            }

            $obligations = $this->calendarGenerator->forYear(
                $deadlines,
                $year,
                $business->vat_status === 'registered',
                $business->employees()->where('status', 'active')->exists(),
            );
            $created = 0;

            foreach ($obligations as $obligation) {
                if ($this->taskRepository->obligationExists($business, $obligation['obligation_type'], $obligation['period_label'])) {
                    continue;
                }

                $this->taskRepository->create($business, [
                    'kind' => 'tax_filing',
                    'obligation_type' => $obligation['obligation_type'],
                    'period_label' => $obligation['period_label'],
                    'title' => $obligation['title'],
                    'due_on' => $obligation['due_on'],
                    'status' => 'open',
                    'compliance_rule_set_id' => $ruleSet->getKey(),
                    'created_by' => $user->getKey(),
                ]);
                $created++;
            }

            $business->auditLogs()->create([
                'user_id' => $user->getKey(),
                'action' => 'tax_calendar.generated',
                'subject_type' => BusinessTask::class,
                'new_values' => ['year' => $year, 'created' => $created, 'rule_version' => $ruleSet->version],
            ]);

            return ['created' => $created, 'rule_version' => $ruleSet->version];
        });
    }

    /**
     * Mark a task done. A tax filing must keep its confirmation document, as the declaration record.
     *
     * @param  array{completed_on: string, reference?: string|null, amount?: string|null, document_id?: int|null}  $attributes
     */
    public function completeTask(Business $business, User $user, int $taskId, array $attributes): BusinessTask
    {
        return DB::transaction(function () use ($business, $user, $taskId, $attributes): BusinessTask {
            $task = $this->taskRepository->findForUpdate($business, $taskId);

            if ($task->status !== 'open') {
                throw ValidationException::withMessages(['task' => ['This task is already completed.']]);
            }

            $documentId = $attributes['document_id'] ?? null;

            if ($task->kind === 'tax_filing' && $documentId === null) {
                throw ValidationException::withMessages(['document_id' => ['Attach the filing confirmation before marking this declaration as filed.']]);
            }

            if ($documentId !== null && ! $business->documents()->whereKey($documentId)->exists()) {
                throw ValidationException::withMessages(['document_id' => ['Choose a document that belongs to this business.']]);
            }

            $task->forceFill([
                'status' => 'done',
                'completed_on' => $attributes['completed_on'],
                'reference' => $attributes['reference'] ?? null,
                'amount' => $attributes['amount'] ?? null,
                'document_id' => $documentId,
                'completed_by' => $user->getKey(),
            ]);
            $task = $this->taskRepository->save($task);
            $this->audit($business, $user, $task->kind === 'tax_filing' ? 'tax_filing.filed' : 'task.completed', $task, ['status' => 'open'], [
                'status' => 'done',
                'completed_on' => $attributes['completed_on'],
                'reference' => $task->reference,
                'amount' => $task->amount,
                'document_id' => $documentId,
            ]);

            return $task;
        });
    }

    private function assertAssigneeIsMember(Business $business, ?int $userId): void
    {
        if ($userId !== null && ! $business->users()->whereKey($userId)->wherePivot('status', 'active')->exists()) {
            throw ValidationException::withMessages(['assigned_to' => ['Assign the task to an active member of this business.']]);
        }
    }

    private function auditValue(mixed $value): mixed
    {
        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value;
    }

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    private function audit(Business $business, User $user, string $action, BusinessTask $task, ?array $old, ?array $new): void
    {
        $business->auditLogs()->create([
            'user_id' => $user->getKey(),
            'action' => $action,
            'subject_type' => BusinessTask::class,
            'subject_id' => $task->getKey(),
            'old_values' => $old,
            'new_values' => $new,
        ]);
    }
}
