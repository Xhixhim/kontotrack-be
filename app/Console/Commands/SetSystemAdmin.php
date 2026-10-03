<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('kontotrack:system-admin {email} {--revoke}')]
#[Description('Grant or revoke system-admin access for a KontoTrack user')]
class SetSystemAdmin extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->error('No user was found with that email address.');

            return self::FAILURE;
        }

        $isSystemAdmin = ! $this->option('revoke');
        $user->forceFill(['is_system_admin' => $isSystemAdmin])->save();

        $this->info($isSystemAdmin ? 'System-admin access granted.' : 'System-admin access revoked.');

        return self::SUCCESS;
    }
}
