<?php

namespace App\Services\Businesses;

use App\Models\Business;
use App\Models\User;
use App\Policies\BusinessPolicy;
use App\Repositories\Contracts\BusinessRepository;
use App\Services\Users\UserAccountService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BusinessService
{
    public function __construct(private BusinessRepository $businessRepository, private UserAccountService $userAccountService) {}

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

    /**
     * Invite a person to the business. Someone without a KontoTrack account gets one, with an emailed link to choose a password.
     *
     * @param  array{email: string, role: string, name?: string|null}  $attributes
     */
    public function inviteMember(Business $business, User $inviter, array $attributes): User
    {
        $accountCreated = false;
        $invitedMember = DB::transaction(function () use ($business, $inviter, $attributes, &$accountCreated): User {
            $member = $this->businessRepository->findUserByEmail($attributes['email']);
            if ($member === null) {
                $member = $this->userAccountService->createWithoutPassword($attributes['name'] ?? Str::before($attributes['email'], '@'), $attributes['email']);
                $accountCreated = true;
            }
            if ((int) $member->getKey() === (int) $inviter->getKey()) {
                throw ValidationException::withMessages(['email' => ['You already have access to this business.']]);
            }
            if ($member->deactivated_at !== null) {
                throw ValidationException::withMessages(['email' => ['This account has been deactivated and cannot be invited.']]);
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
                'new_values' => ['email' => $member->email, 'role' => $attributes['role'], 'status' => 'invited', 'account_created' => $accountCreated],
            ]);

            return $invitedMember;
        });

        if ($accountCreated) {
            $this->userAccountService->invite($invitedMember, $inviter->name, $business->legal_name);
        }

        return $invitedMember;
    }

    /**
     * Change a member's role or the abilities taken away from them. The owner's own access is never changed here.
     *
     * @param  array{role?: string, restrictions?: list<string>}  $attributes
     */
    public function updateMember(Business $business, User $actor, User $member, array $attributes): User
    {
        return DB::transaction(function () use ($business, $actor, $member, $attributes): User {
            $membership = $this->changeableMembership($business, $member);
            $old = ['role' => $membership->pivot->role, 'restrictions' => BusinessPolicy::restrictionsOf($membership->pivot)];
            $new = [
                'role' => $attributes['role'] ?? $old['role'],
                'restrictions' => array_key_exists('restrictions', $attributes) ? array_values(array_intersect(BusinessPolicy::RESTRICTIONS, $attributes['restrictions'])) : $old['restrictions'],
            ];

            if ($new === $old) {
                return $membership;
            }

            $updated = $this->businessRepository->updateMembership($business, $member, [
                'role' => $new['role'],
                'restrictions' => $new['restrictions'] === [] ? null : json_encode($new['restrictions']),
            ]);
            $business->auditLogs()->create([
                'user_id' => $actor->getKey(),
                'action' => 'business.member_updated',
                'subject_type' => User::class,
                'subject_id' => $member->getKey(),
                'old_values' => $old,
                'new_values' => $new,
            ]);

            return $updated;
        });
    }

    public function removeMember(Business $business, User $actor, User $member): void
    {
        DB::transaction(function () use ($business, $actor, $member): void {
            $membership = $this->changeableMembership($business, $member);
            $this->businessRepository->updateMembership($business, $member, ['status' => 'removed', 'restrictions' => null]);
            $business->auditLogs()->create([
                'user_id' => $actor->getKey(),
                'action' => 'business.member_removed',
                'subject_type' => User::class,
                'subject_id' => $member->getKey(),
                'old_values' => ['email' => $member->email, 'role' => $membership->pivot->role, 'status' => $membership->pivot->status],
                'new_values' => ['status' => 'removed'],
            ]);
        });
    }

    private function changeableMembership(Business $business, User $member): User
    {
        $membership = $this->businessRepository->findMember($business, $member);

        if ($membership === null) {
            throw (new ModelNotFoundException)->setModel(User::class, [$member->getKey()]);
        }
        if ($membership->pivot->role === 'owner') {
            throw ValidationException::withMessages(['member' => ['The owner\'s access cannot be changed or removed.']]);
        }

        return $membership;
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
