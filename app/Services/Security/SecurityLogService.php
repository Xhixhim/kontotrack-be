<?php

namespace App\Services\Security;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Records sign-ins, failed sign-ins, and sign-outs in the platform audit log.
 */
class SecurityLogService
{
    public function loginSucceeded(User $user, Request $request, string $channel): void
    {
        $this->record('auth.login', $user->getKey(), $request, ['channel' => $channel]);
    }

    public function loginFailed(string $email, Request $request, string $channel): void
    {
        $this->record('auth.login_failed', User::query()->where('email', $email)->value('id'), $request, ['channel' => $channel, 'email' => $email]);
    }

    public function loggedOut(User $user, Request $request, string $channel): void
    {
        $this->record('auth.logout', $user->getKey(), $request, ['channel' => $channel]);
    }

    /** @param array<string, mixed> $details */
    private function record(string $action, mixed $userId, Request $request, array $details): void
    {
        AuditLog::query()->create([
            'user_id' => $userId,
            'action' => $action,
            'subject_type' => User::class,
            'subject_id' => $userId,
            'new_values' => $details,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000),
        ]);
    }
}
