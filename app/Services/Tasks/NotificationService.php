<?php

namespace App\Services\Tasks;

use App\Models\User;
use App\Repositories\Contracts\DocumentRequestRepository;
use App\Repositories\Contracts\TaskRepository;
use Illuminate\Support\Carbon;

/**
 * In-app reminders for a user across every business they belong to.
 */
class NotificationService
{
    private const DUE_SOON_DAYS = 7;

    public function __construct(
        private TaskRepository $taskRepository,
        private DocumentRequestRepository $documentRequestRepository,
    ) {}

    /** @return list<array{type: string, severity: string, business_id: int, business_name: string, title: string, due_on: string|null, task_id: int|null}> */
    public function forUser(User $user, string $today): array
    {
        $memberships = $user->businesses()->wherePivot('status', 'active')->get(['businesses.id', 'legal_name', 'trade_name']);
        $notifications = [];

        foreach ($this->taskRepository->openDueBy($memberships->pluck('id')->map(fn ($id): int => (int) $id)->all(), Carbon::parse($today)->addDays(self::DUE_SOON_DAYS)->toDateString()) as $task) {
            if ($task->assigned_to !== null && (int) $task->assigned_to !== (int) $user->getKey()) {
                continue;
            }

            $isOverdue = $task->due_on->toDateString() < $today;
            $notifications[] = [
                'type' => $isOverdue ? 'task_overdue' : 'task_due_soon',
                'severity' => $isOverdue ? 'critical' : 'warning',
                'business_id' => (int) $task->business_id,
                'business_name' => (string) ($task->business->trade_name ?: $task->business->legal_name),
                'title' => $task->title,
                'due_on' => $task->due_on->toDateString(),
                'task_id' => (int) $task->getKey(),
            ];
        }

        $rolesByBusiness = $memberships->mapWithKeys(fn ($business): array => [(int) $business->getKey() => (string) $business->pivot->role]);
        $businessIds = $rolesByBusiness->keys()->all();

        foreach ($this->documentRequestRepository->withStatus($businessIds, 'open') as $request) {
            if (! in_array($rolesByBusiness[$request->business_id], ['owner', 'assistant_accountant'], true)) {
                continue;
            }

            $notifications[] = [
                'type' => 'document_requested',
                'severity' => $request->due_on !== null && $request->due_on->toDateString() < $today ? 'critical' : 'warning',
                'business_id' => (int) $request->business_id,
                'business_name' => (string) ($request->business->trade_name ?: $request->business->legal_name),
                'title' => $request->title,
                'due_on' => $request->due_on?->toDateString(),
                'task_id' => null,
            ];
        }

        foreach ($this->documentRequestRepository->withStatus($businessIds, 'submitted') as $request) {
            if (! in_array($rolesByBusiness[$request->business_id], ['owner', 'accountant'], true)) {
                continue;
            }

            $notifications[] = [
                'type' => 'document_submitted',
                'severity' => 'info',
                'business_id' => (int) $request->business_id,
                'business_name' => (string) ($request->business->trade_name ?: $request->business->legal_name),
                'title' => "Submitted for review: {$request->title}",
                'due_on' => $request->due_on?->toDateString(),
                'task_id' => null,
            ];
        }

        foreach ($user->businesses()->wherePivot('status', 'invited')->get(['businesses.id', 'legal_name', 'trade_name']) as $invitation) {
            $notifications[] = [
                'type' => 'invitation',
                'severity' => 'info',
                'business_id' => (int) $invitation->getKey(),
                'business_name' => (string) ($invitation->trade_name ?: $invitation->legal_name),
                'title' => 'You have been invited to join this business.',
                'due_on' => null,
                'task_id' => null,
            ];
        }

        return $notifications;
    }
}
