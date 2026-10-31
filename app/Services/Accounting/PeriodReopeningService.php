<?php

namespace App\Services\Accounting;

use App\Models\AccountingPeriod;
use App\Models\Business;
use App\Models\PeriodReopenRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Controlled reopening of a closed accounting period: someone who keeps the books asks with a
 * reason, and a different person with authority (the business owner or a system administrator)
 * approves or rejects. Every step is in the audit trail, and the period can be closed again
 * through the normal closing checks.
 */
class PeriodReopeningService
{
    /** @return Collection<int, PeriodReopenRequest> */
    public function listForPeriod(Business $business, int $periodId): Collection
    {
        $period = $business->accountingPeriods()->whereKey($periodId)->firstOrFail();

        return $period->reopenRequests()->with(['requester:id,name', 'decider:id,name'])->latest('id')->get();
    }

    public function request(Business $business, User $user, int $periodId, string $reason): PeriodReopenRequest
    {
        return DB::transaction(function () use ($business, $user, $periodId, $reason): PeriodReopenRequest {
            $period = $this->lockedPeriod($business, $periodId);
            $this->assertCanReopen($business, $period);

            if ($period->reopenRequests()->where('status', 'pending')->exists()) {
                throw ValidationException::withMessages(['accounting_period' => ['A request to reopen this period is already waiting for approval.']]);
            }

            $request = $period->reopenRequests()->create([
                'business_id' => $business->getKey(),
                'requested_by' => $user->getKey(),
                'reason' => $reason,
                'status' => 'pending',
            ]);
            $this->audit($business, $user, 'accounting_period.reopen_requested', $period, [], ['request_id' => $request->getKey(), 'reason' => $reason]);

            return $request->load(['requester:id,name', 'decider:id,name']);
        });
    }

    public function approve(Business $business, User $approver, int $periodId, int $requestId, ?string $note): PeriodReopenRequest
    {
        return DB::transaction(function () use ($business, $approver, $periodId, $requestId, $note): PeriodReopenRequest {
            $period = $this->lockedPeriod($business, $periodId);
            $request = $this->pendingRequest($period, $requestId);
            $this->assertDifferentPerson($request, $approver);
            $this->assertCanReopen($business, $period);

            $request->forceFill([
                'status' => 'approved',
                'decided_by' => $approver->getKey(),
                'decided_at' => now(),
                'decision_note' => $note,
                'period_closed_at' => $period->closed_at,
                'period_closed_by' => $period->closed_by,
            ])->save();
            $old = ['status' => 'closed', 'closed_at' => $period->closed_at?->toISOString(), 'closed_by' => $period->closed_by];
            $period->forceFill(['status' => 'open', 'closed_at' => null, 'closed_by' => null, 'reopened_at' => now(), 'reopened_by' => $approver->getKey()])->save();
            $this->audit($business, $approver, 'accounting_period.reopened', $period, $old, [
                'status' => 'open',
                'request_id' => $request->getKey(),
                'requested_by' => $request->requested_by,
                'reason' => $request->reason,
                'decision_note' => $note,
            ]);

            return $request->load(['requester:id,name', 'decider:id,name']);
        });
    }

    public function reject(Business $business, User $approver, int $periodId, int $requestId, string $note): PeriodReopenRequest
    {
        return DB::transaction(function () use ($business, $approver, $periodId, $requestId, $note): PeriodReopenRequest {
            $period = $this->lockedPeriod($business, $periodId);
            $request = $this->pendingRequest($period, $requestId);
            $this->assertDifferentPerson($request, $approver);

            $request->forceFill(['status' => 'rejected', 'decided_by' => $approver->getKey(), 'decided_at' => now(), 'decision_note' => $note])->save();
            $this->audit($business, $approver, 'accounting_period.reopen_rejected', $period, [], ['request_id' => $request->getKey(), 'decision_note' => $note]);

            return $request->load(['requester:id,name', 'decider:id,name']);
        });
    }

    public function cancel(Business $business, User $user, int $periodId, int $requestId): PeriodReopenRequest
    {
        return DB::transaction(function () use ($business, $user, $periodId, $requestId): PeriodReopenRequest {
            $period = $this->lockedPeriod($business, $periodId);
            $request = $this->pendingRequest($period, $requestId);

            if ((int) $request->requested_by !== (int) $user->getKey()) {
                throw ValidationException::withMessages(['request' => ['Only the person who asked can withdraw this request.']]);
            }

            $request->forceFill(['status' => 'cancelled', 'decided_by' => $user->getKey(), 'decided_at' => now()])->save();
            $this->audit($business, $user, 'accounting_period.reopen_cancelled', $period, [], ['request_id' => $request->getKey()]);

            return $request->load(['requester:id,name', 'decider:id,name']);
        });
    }

    /**
     * Only the latest closed period can be reopened: later closed periods carry its balances forward,
     * so they must be reopened first.
     */
    private function assertCanReopen(Business $business, AccountingPeriod $period): void
    {
        if ($period->status !== 'closed') {
            throw ValidationException::withMessages(['accounting_period' => ['Only a closed period can be reopened.']]);
        }

        $laterClosed = $business->accountingPeriods()->where('status', 'closed')->whereDate('starts_on', '>', $period->ends_on->toDateString())->orderBy('starts_on')->first();

        if ($laterClosed !== null) {
            throw ValidationException::withMessages(['accounting_period' => ["Reopen the later closed period \"{$laterClosed->name}\" first; it carries this period's balances forward."]]);
        }
    }

    private function assertDifferentPerson(PeriodReopenRequest $request, User $approver): void
    {
        if ($request->requested_by !== null && (int) $request->requested_by === (int) $approver->getKey()) {
            throw ValidationException::withMessages(['request' => ['Someone other than the person who asked must approve or reject this request.']]);
        }
    }

    private function lockedPeriod(Business $business, int $periodId): AccountingPeriod
    {
        return $business->accountingPeriods()->whereKey($periodId)->lockForUpdate()->firstOrFail();
    }

    private function pendingRequest(AccountingPeriod $period, int $requestId): PeriodReopenRequest
    {
        $request = $period->reopenRequests()->whereKey($requestId)->lockForUpdate()->firstOrFail();

        if ($request->status !== 'pending') {
            throw ValidationException::withMessages(['request' => ['This request has already been decided.']]);
        }

        return $request;
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    private function audit(Business $business, User $user, string $action, AccountingPeriod $period, array $old, array $new): void
    {
        $business->auditLogs()->create([
            'user_id' => $user->getKey(),
            'action' => $action,
            'subject_type' => AccountingPeriod::class,
            'subject_id' => $period->getKey(),
            'old_values' => $old === [] ? null : $old,
            'new_values' => $new,
        ]);
    }
}
