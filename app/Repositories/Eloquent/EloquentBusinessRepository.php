<?php

namespace App\Repositories\Eloquent;

use App\Models\Business;
use App\Models\User;
use App\Repositories\Contracts\BusinessRepository;
use Illuminate\Database\Eloquent\Collection;

class EloquentBusinessRepository implements BusinessRepository
{
    /** @return Collection<int, Business> */
    public function findActiveForUser(User $user): Collection
    {
        return Business::query()
            ->where('businesses.status', 'active')
            ->whereHas('users', fn ($query) => $query
                ->whereKey($user->getKey())
                ->where('business_user.status', 'active'))
            ->with(['users' => fn ($query) => $query
                ->whereKey($user->getKey())
                ->wherePivot('status', 'active')])
            ->orderBy('legal_name')
            ->get();
    }

    /** @return Collection<int, User> */
    public function findChatMembers(Business $business): Collection
    {
        return $business->users()
            ->wherePivot('status', 'active')
            ->wherePivotIn('role', ['owner', 'accountant', 'assistant_accountant'])
            ->orderBy('name')
            ->get();
    }

    /** @return Collection<int, User> */
    public function findMembers(Business $business): Collection
    {
        return $business->users()->orderBy('name')->get();
    }

    /** @return Collection<int, Business> */
    public function findInvitationsForUser(User $user): Collection
    {
        return $user->businesses()->wherePivot('status', 'invited')->orderBy('legal_name')->get();
    }

    public function findUserByEmail(string $email): ?User
    {
        return User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->first();
    }

    public function saveInvitation(Business $business, User $member, User $inviter, string $role): User
    {
        $attributes = [
            'role' => $role,
            'status' => 'invited',
            'invited_by' => $inviter->getKey(),
            'invited_at' => now(),
            'accepted_at' => null,
        ];

        if ($business->users()->whereKey($member->getKey())->exists()) {
            $business->users()->updateExistingPivot($member->getKey(), $attributes);
        } else {
            $business->users()->attach($member->getKey(), $attributes);
        }

        return $business->users()->whereKey($member->getKey())->firstOrFail();
    }

    public function acceptInvitation(Business $business, User $member): void
    {
        $business->users()->updateExistingPivot($member->getKey(), [
            'status' => 'active',
            'accepted_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $attributes */
    public function save(Business $business): Business
    {
        $business->save();

        return $business;
    }

    public function createForOwner(User $user, array $attributes): Business
    {
        $business = Business::query()->create($attributes);
        $business->users()->attach($user->getKey(), [
            'role' => 'owner',
            'status' => 'active',
            'accepted_at' => now(),
        ]);

        return $business;
    }
}
