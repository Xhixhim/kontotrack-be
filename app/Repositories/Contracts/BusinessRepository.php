<?php

namespace App\Repositories\Contracts;

use App\Models\Business;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

interface BusinessRepository
{
    /** @return Collection<int, Business> */
    public function findActiveForUser(User $user): Collection;

    /** @return Collection<int, User> */
    public function findChatMembers(Business $business): Collection;

    /** @return Collection<int, User> */
    public function findMembers(Business $business): Collection;

    /** @return Collection<int, Business> */
    public function findInvitationsForUser(User $user): Collection;

    public function findUserByEmail(string $email): ?User;

    public function saveInvitation(Business $business, User $member, User $inviter, string $role): User;

    public function acceptInvitation(Business $business, User $member): void;

    public function save(Business $business): Business;

    /** @param array<string, mixed> $attributes */
    public function createForOwner(User $user, array $attributes): Business;
}
