<?php

namespace App\Services\Users;

use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\AccountInvitation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Passwords and platform-level user accounts: reset links, password changes, and the accounts a system administrator manages.
 */
class UserAccountService
{
    /**
     * Email a reset link when the address belongs to an active account. Callers always answer the same way,
     * so the response never reveals whether an address is registered.
     */
    public function sendPasswordResetLink(string $email, Request $request): void
    {
        $status = Password::sendResetLink([
            'email' => $email,
            fn (Builder $query) => $query->whereNull('deactivated_at'),
        ]);

        if ($status === Password::RESET_LINK_SENT) {
            $this->record('auth.password_reset_requested', User::query()->where('email', $email)->value('id'), $request);
        }
    }

    /** @param array{email: string, token: string, password: string} $attributes */
    public function resetPassword(array $attributes, Request $request): void
    {
        $status = Password::reset([
            'email' => $attributes['email'],
            'token' => $attributes['token'],
            'password' => $attributes['password'],
            fn (Builder $query) => $query->whereNull('deactivated_at'),
        ], function (User $user, string $password) use ($request): void {
            $user->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),
                // Opening the emailed link proves the address belongs to this person.
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();
            $this->endSessions($user);
            $this->record('auth.password_reset', $user->getKey(), $request);
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => ['This password link is not valid or has expired. Ask for a new one.']]);
        }
    }

    public function changePassword(User $user, string $currentPassword, string $newPassword, Request $request): void
    {
        if (! Hash::check($currentPassword, $user->password)) {
            throw ValidationException::withMessages(['current_password' => ['The current password is not correct.']]);
        }

        $user->forceFill(['password' => $newPassword, 'remember_token' => Str::random(60)])->save();
        $this->endSessions($user, $request);
        $this->record('auth.password_changed', $user->getKey(), $request);
    }

    /** @return Collection<int, User> */
    public function listUsers(): Collection
    {
        return User::query()->withCount(['businesses' => fn ($query) => $query->where('business_user.status', 'active')])->orderBy('name')->get();
    }

    /** @param array{name: string, email: string, is_system_admin?: bool} $attributes */
    public function createUser(User $administrator, array $attributes, Request $request): User
    {
        $user = DB::transaction(function () use ($administrator, $attributes, $request): User {
            $user = $this->createWithoutPassword($attributes['name'], $attributes['email']);
            $user->forceFill(['is_system_admin' => (bool) ($attributes['is_system_admin'] ?? false)])->save();
            $this->record('user.created', $user->getKey(), $request, ['email' => $user->email, 'is_system_admin' => $user->is_system_admin], $administrator);

            return $user;
        });
        $this->invite($user, $administrator->name);

        return $user->loadCount(['businesses' => fn ($query) => $query->where('business_user.status', 'active')]);
    }

    /** @param array{name?: string, email?: string, is_system_admin?: bool, is_active?: bool} $attributes */
    public function updateUser(User $administrator, User $user, array $attributes, Request $request): User
    {
        return DB::transaction(function () use ($administrator, $user, $attributes, $request): User {
            $isSelf = (int) $administrator->getKey() === (int) $user->getKey();

            if ($isSelf && (($attributes['is_system_admin'] ?? true) === false || ($attributes['is_active'] ?? true) === false)) {
                throw ValidationException::withMessages(['user' => ['You cannot remove your own administrator access or deactivate your own account.']]);
            }

            $user->fill(array_intersect_key($attributes, ['name' => true, 'email' => true]));

            if (array_key_exists('is_system_admin', $attributes)) {
                $user->is_system_admin = (bool) $attributes['is_system_admin'];
            }

            if (array_key_exists('is_active', $attributes)) {
                $user->deactivated_at = $attributes['is_active'] ? null : ($user->deactivated_at ?? now());
            }

            $newValues = $user->getDirty();

            if ($newValues !== []) {
                $oldValues = array_map(fn (string $attribute): mixed => $user->getRawOriginal($attribute), array_combine(array_keys($newValues), array_keys($newValues)));
                $user->save();
                $this->record('user.updated', $user->getKey(), $request, $newValues, $administrator, $oldValues);

                if ($user->deactivated_at !== null) {
                    $this->endSessions($user);
                }
            }

            return $user->loadCount(['businesses' => fn ($query) => $query->where('business_user.status', 'active')]);
        });
    }

    /**
     * Create an account nobody knows the password of; the person sets one through an emailed link.
     */
    public function createWithoutPassword(string $name, string $email): User
    {
        return User::query()->create(['name' => $name, 'email' => mb_strtolower($email), 'password' => Str::random(40)]);
    }

    public function invite(User $user, string $inviterName, ?string $businessName = null): void
    {
        $user->notify(new AccountInvitation(Password::createToken($user), $inviterName, $businessName));
    }

    /**
     * Sign the user out everywhere, except the session or token making this request.
     */
    private function endSessions(User $user, ?Request $request = null): void
    {
        $currentToken = $request?->user()?->currentAccessToken();
        $user->tokens()->when($currentToken !== null && isset($currentToken->id), fn ($query) => $query->whereKeyNot($currentToken->id))->delete();

        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))
                ->where('user_id', $user->getKey())
                ->when($request?->hasSession(), fn ($query) => $query->where('id', '!=', $request->session()->getId()))
                ->delete();
        }
    }

    /**
     * @param  array<string, mixed>  $newValues
     * @param  array<string, mixed>|null  $oldValues
     */
    private function record(string $action, mixed $subjectId, Request $request, array $newValues = [], ?User $actor = null, ?array $oldValues = null): void
    {
        AuditLog::query()->create([
            'user_id' => $actor?->getKey() ?? $subjectId,
            'action' => $action,
            'subject_type' => User::class,
            'subject_id' => $subjectId,
            'old_values' => $oldValues,
            'new_values' => $newValues === [] ? null : $newValues,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000),
        ]);
    }
}
