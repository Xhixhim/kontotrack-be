<?php

namespace App\Policies;

use App\Models\Business;
use App\Models\User;

class BusinessPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return false;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Business $business): bool
    {
        return $this->hasActiveMembership($user, $business);
    }

    public function uploadDocuments(User $user, Business $business): bool
    {
        return $business->users()
            ->whereKey($user->getKey())
            ->wherePivot('status', 'active')
            ->wherePivotIn('role', ['owner', 'accountant', 'assistant_accountant'])
            ->exists();
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Business $business): bool
    {
        return $business->users()
            ->whereKey($user->getKey())
            ->wherePivot('status', 'active')
            ->wherePivotIn('role', ['owner', 'accountant'])
            ->exists();
    }

    public function manageBookkeeping(User $user, Business $business): bool
    {
        return $this->update($user, $business);
    }

    /**
     * Reopening a closed period changes figures that may already have been filed, so only the
     * business owner (or a system administrator) can approve it.
     */
    public function approvePeriodReopening(User $user, Business $business): bool
    {
        return (bool) $user->is_system_admin || $this->manageMembers($user, $business);
    }

    public function viewAuditTrail(User $user, Business $business): bool
    {
        return $business->users()
            ->whereKey($user->getKey())
            ->wherePivot('status', 'active')
            ->wherePivotIn('role', ['owner', 'accountant', 'auditor'])
            ->exists();
    }

    public function viewPayroll(User $user, Business $business): bool
    {
        return $this->viewAuditTrail($user, $business);
    }

    public function manageMembers(User $user, Business $business): bool
    {
        return $business->users()
            ->whereKey($user->getKey())
            ->wherePivot('status', 'active')
            ->wherePivot('role', 'owner')
            ->exists();
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Business $business): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Business $business): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Business $business): bool
    {
        return false;
    }

    private function hasActiveMembership(User $user, Business $business): bool
    {
        return $business->users()
            ->whereKey($user->getKey())
            ->wherePivot('status', 'active')
            ->exists();
    }
}
