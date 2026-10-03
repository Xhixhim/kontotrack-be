<?php

namespace App\Services\Businesses;

use App\Models\Business;
use App\Models\User;
use App\Repositories\Contracts\BusinessRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BusinessService
{
    public function __construct(private BusinessRepository $businessRepository) {}

    /** @return Collection<int, Business> */
    public function listForUser(User $user): Collection
    {
        return $this->businessRepository->findActiveForUser($user);
    }

    /** @return Collection<int, User> */
    public function chatMembers(Business $business): Collection
    {
        return $this->businessRepository->findChatMembers($business);
    }

    /** @return Collection<int, User> */
    public function membersForManagement(Business $business): Collection
    {
        return $this->businessRepository->findMembers($business);
    }

    /** @return Collection<int, Business> */
    public function invitationsForUser(User $user): Collection
    {
        return $this->businessRepository->findInvitationsForUser($user);
    }

    /** @param array{email: string, role: string} $attributes */
    public function inviteMember(Business $business, User $inviter, array $attributes): User
    {
        return DB::transaction(function () use ($business, $inviter, $attributes): User {
            $member = $this->businessRepository->findUserByEmail($attributes['email']);
            if ($member === null) {
                throw ValidationException::withMessages(['email' => ['No KontoTrack user has this email address yet.']]);
            }
            if ((int) $member->getKey() === (int) $inviter->getKey()) {
                throw ValidationException::withMessages(['email' => ['You already have access to this business.']]);
            }

            $existing = $business->users()->whereKey($member->getKey())->first();
            if ($existing !== null && $existing->pivot->status === 'active') {
                throw ValidationException::withMessages(['email' => ['This user is already an active member of the business.']]);
            }
            if ($existing !== null && $existing->pivot->role === 'owner') {
                throw ValidationException::withMessages(['email' => ['An existing business owner cannot be re-invited with another role.']]);
            }

            $invitedMember = $this->businessRepository->saveInvitation($business, $member, $inviter, $attributes['role']);
            $business->auditLogs()->create([
                'user_id' => $inviter->getKey(),
                'action' => 'business.member_invited',
                'subject_type' => User::class,
                'subject_id' => $member->getKey(),
                'old_values' => $existing === null ? null : ['role' => $existing->pivot->role, 'status' => $existing->pivot->status],
                'new_values' => ['email' => $member->email, 'role' => $attributes['role'], 'status' => 'invited'],
            ]);

            return $invitedMember;
        });
    }

    public function acceptInvitation(Business $business, User $user): User
    {
        return DB::transaction(function () use ($business, $user): User {
            $membership = $business->users()->whereKey($user->getKey())->first();
            if ($membership === null || $membership->pivot->status !== 'invited') {
                throw ValidationException::withMessages(['invitation' => ['There is no pending invitation for this account and business.']]);
            }

            $this->businessRepository->acceptInvitation($business, $user);
            $business->auditLogs()->create([
                'user_id' => $user->getKey(),
                'action' => 'business.invitation_accepted',
                'subject_type' => User::class,
                'subject_id' => $user->getKey(),
                'old_values' => ['role' => $membership->pivot->role, 'status' => 'invited'],
                'new_values' => ['role' => $membership->pivot->role, 'status' => 'active'],
            ]);

            return $business->users()->whereKey($user->getKey())->firstOrFail();
        });
    }

    /** @param array<string, mixed> $attributes */
    /** @param array<string, mixed> $attributes */
    public function update(Business $business, User $user, array $attributes): Business
    {
        return DB::transaction(function () use ($business, $user, $attributes): Business {
            $business->fill($attributes);
            $newValues = $business->getDirty();

            if ($newValues === []) {
                return $business;
            }

            $oldValues = array_map(fn (string $attribute): mixed => $business->getRawOriginal($attribute), array_combine(array_keys($newValues), array_keys($newValues)));
            $this->businessRepository->save($business);
            $business->auditLogs()->create([
                'user_id' => $user->getKey(),
                'action' => 'business.updated',
                'subject_type' => Business::class,
                'subject_id' => $business->getKey(),
                'old_values' => $oldValues,
                'new_values' => $newValues,
            ]);

            return $business;
        });
    }

    public function createForOwner(User $user, array $attributes): Business
    {
        return DB::transaction(function () use ($user, $attributes): Business {
            $business = $this->businessRepository->createForOwner($user, $attributes);
            $business->auditLogs()->create([
                'user_id' => $user->getKey(),
                'action' => 'business.created',
                'subject_type' => Business::class,
                'subject_id' => $business->getKey(),
                'new_values' => $business->only(['legal_name', 'nui', 'legal_form', 'vat_status']),
            ]);

            return $business->load(['users' => fn ($query) => $query->whereKey($user->getKey())]);
        });
    }
}
