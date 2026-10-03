<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DevelopmentWorkspaceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! app()->isLocal()) {
            return;
        }

        $name = env('KONTOTRACK_LOCAL_USER_NAME');
        $email = env('KONTOTRACK_LOCAL_USER_EMAIL');
        $password = env('KONTOTRACK_LOCAL_USER_PASSWORD');
        $businessName = env('KONTOTRACK_LOCAL_BUSINESS_NAME');
        $nui = env('KONTOTRACK_LOCAL_BUSINESS_NUI');

        if (! $name || ! $email || ! $password || ! $businessName || ! $nui) {
            return;
        }

        $user = User::query()->firstOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => Hash::make($password)],
        );

        $business = Business::query()->firstOrCreate(
            ['nui' => $nui],
            ['legal_name' => $businessName, 'vat_status' => 'unknown'],
        );

        $user->businesses()->syncWithoutDetaching([
            $business->getKey() => ['role' => 'owner', 'status' => 'active', 'accepted_at' => now()],
        ]);
    }
}
