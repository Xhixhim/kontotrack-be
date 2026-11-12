<?php

namespace App\Policies;

use App\Models\Business;
use App\Models\User;

class BusinessPolicy
{
    /**
     * What each role may do. A restriction is something the owner can take away from one member
     * without changing that member's role; the owner's own access cannot be restricted.
     *
     * @var array<string, array{roles: list<string>, restriction: string|null}>
     */
    private const ABILITIES = [
        'manage_bookkeeping' => ['roles' => ['owner', 'accountant'], 'restriction' => 'bookkeeping'],
        'upload_documents' => ['roles' => ['owner', 'accountant', 'assistant_accountant'], 'restriction' => 'documents'],
        'view_audit_trail' => ['roles' => ['owner', 'accountant', 'auditor'], 'restriction' => 'audit_trail'],
        'view_payroll' => ['roles' => ['owner', 'accountant', 'auditor'], 'restriction' => 'payroll'],
        'manage_members' => ['roles' => ['owner'], 'restriction' => null],
    ];

    /** @var list<string> */
    public const RESTRICTIONS = ['bookkeeping', 'documents', 'audit_trail', 'payroll'];

    /**
     * @param  list<string>  $restrictions
     * @return array<string, bool>
     */
    public static function abilities(string $role, array $restrictions): array
    {
        return array_map(
            fn (array $ability): bool => in_array($role, $ability['roles'], true)
                && ($role === 'owner' || $ability['restriction'] === null || ! in_array($ability['restriction'], $restrictions, true)),
            self::ABILITIES,
        );
    }

    /** @return list<string> */
    public static function restrictionsOf(?object $membership): array
    {
        $restrictions = json_decode((string) ($membership->restrictions ?? ''), true);

        return is_array($restrictions) ? array_values(array_intersect(self::RESTRICTIONS, $restrictions)) : [];
    }

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
        return $this->allows($user, $business, 'upload_documents');
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
        return $this->allows($user, $business, 'manage_bookkeeping');
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
        return $this->allows($user, $business, 'view_audit_trail');
    }

    public function viewPayroll(User $user, Business $business): bool
    {
        return $this->allows($user, $business, 'view_payroll');
    }

    public function manageMembers(User $user, Business $business): bool
    {
        return $this->allows($user, $business, 'manage_members');
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

    private function allows(User $user, Business $business, string $ability): bool
    {
        $membership = $business->users()->whereKey($user->getKey())->wherePivot('status', 'active')->first()?->pivot;

        return $membership !== null && self::abilities((string) $membership->role, self::restrictionsOf($membership))[$ability];
    }

    private function hasActiveMembership(User $user, Business $business): bool
    {
        return $business->users()
            ->whereKey($user->getKey())
            ->wherePivot('status', 'active')
            ->exists();
    }
}
