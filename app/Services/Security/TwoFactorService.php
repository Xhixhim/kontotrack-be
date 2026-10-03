<?php

namespace App\Services\Security;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Optional second sign-in step with an authenticator app, plus one-time recovery codes.
 */
class TwoFactorService
{
    public function __construct(private TotpService $totp) {}

    /** @return array{secret: string, otpauth_uri: string} */
    public function begin(User $user): array
    {
        if ($user->hasTwoFactorEnabled()) {
            throw ValidationException::withMessages(['two_factor' => ['Two-factor sign-in is already turned on.']]);
        }

        $secret = $this->totp->generateSecret();
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => null, 'two_factor_recovery_codes' => null])->save();

        return ['secret' => $secret, 'otpauth_uri' => $this->totp->provisioningUri($secret, $user->email, (string) config('app.name', 'KontoTrack'))];
    }

    /** @return list<string> The recovery codes, shown to the user once. */
    public function confirm(User $user, string $code): array
    {
        if ($user->two_factor_secret === null || $user->hasTwoFactorEnabled()) {
            throw ValidationException::withMessages(['code' => ['Start two-factor setup first.']]);
        }

        $step = $this->totp->matchingStep($user->two_factor_secret, $code, now()->getTimestamp());

        if ($step === null) {
            throw ValidationException::withMessages(['code' => ['That code is not valid. Check the time on your phone and try again.']]);
        }

        $recoveryCodes = array_map(fn (): string => Str::upper(Str::random(5).'-'.Str::random(5)), range(1, 8));
        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_last_used_step' => $step,
            'two_factor_recovery_codes' => array_map(fn (string $recoveryCode): string => Hash::make($recoveryCode), $recoveryCodes),
        ])->save();
        $this->audit($user, 'two_factor.enabled');

        return $recoveryCodes;
    }

    public function disable(User $user, string $password, string $code): void
    {
        if (! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['password' => ['The password is not correct.']]);
        }

        if (! $user->hasTwoFactorEnabled() || ! $this->passes($user, $code, null)) {
            throw ValidationException::withMessages(['code' => ['That code is not valid.']]);
        }

        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null, 'two_factor_last_used_step' => null])->save();
        $this->audit($user, 'two_factor.disabled');
    }

    /**
     * Check the second step at sign-in: an authenticator code that has not been used before, or an unused recovery code.
     */
    public function passes(User $user, ?string $code, ?string $recoveryCode): bool
    {
        return DB::transaction(function () use ($user, $code, $recoveryCode): bool {
            $user = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            if ($code !== null && $code !== '') {
                $step = $this->totp->matchingStep((string) $user->two_factor_secret, $code, now()->getTimestamp());

                if ($step === null || ($user->two_factor_last_used_step !== null && $step <= (int) $user->two_factor_last_used_step)) {
                    return false;
                }

                $user->forceFill(['two_factor_last_used_step' => $step])->save();

                return true;
            }

            if ($recoveryCode !== null && $recoveryCode !== '') {
                $remaining = $user->two_factor_recovery_codes ?? [];

                foreach ($remaining as $index => $hash) {
                    if (Hash::check(Str::upper(trim($recoveryCode)), $hash)) {
                        unset($remaining[$index]);
                        $user->forceFill(['two_factor_recovery_codes' => array_values($remaining)])->save();
                        $this->audit($user, 'two_factor.recovery_code_used', ['remaining' => count($remaining)]);

                        return true;
                    }
                }
            }

            return false;
        });
    }

    /** @param array<string, mixed> $details */
    private function audit(User $user, string $action, array $details = []): void
    {
        AuditLog::query()->create([
            'user_id' => $user->getKey(),
            'action' => $action,
            'subject_type' => User::class,
            'subject_id' => $user->getKey(),
            'new_values' => $details === [] ? null : $details,
            'ip_address' => request()->ip(),
            'user_agent' => mb_substr((string) request()->userAgent(), 0, 1000),
        ]);
    }
}
