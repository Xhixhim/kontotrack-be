<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\ReminderDigest;
use App\Services\Tasks\NotificationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('kontotrack:reminders:send')]
#[Description('Email each user the deadlines, document requests and approvals that need their attention')]
class SendReminderDigests extends Command
{
    /** An unchanged list is repeated after this many days, not every morning. */
    private const REPEAT_AFTER_DAYS = 7;

    /**
     * Execute the console command.
     */
    public function handle(NotificationService $notificationService): int
    {
        $today = now(config('kontotrack.display_timezone'))->toDateString();
        $sent = 0;
        $failed = 0;

        User::query()->whereNull('deactivated_at')->where('email_notifications', true)->chunkById(200, function ($users) use ($notificationService, $today, &$sent, &$failed): void {
            foreach ($users as $user) {
                $reminders = $notificationService->forUser($user, $today);

                if ($reminders === []) {
                    continue;
                }

                $hash = hash('sha256', json_encode(array_map(fn (array $reminder): array => [$reminder['type'], $reminder['business_id'], $reminder['title'], $reminder['due_on']], $reminders), JSON_THROW_ON_ERROR));
                $repeatedTooSoon = $user->notification_digest_hash === $hash
                    && $user->notification_digest_sent_at !== null
                    && $user->notification_digest_sent_at->gt(now()->subDays(self::REPEAT_AFTER_DAYS));

                if ($repeatedTooSoon) {
                    continue;
                }

                try {
                    $user->notify(new ReminderDigest($reminders));
                    $user->forceFill(['notification_digest_hash' => $hash, 'notification_digest_sent_at' => now()])->save();
                    $sent++;
                } catch (Throwable $exception) {
                    Log::error('Reminder email could not be sent.', ['user_id' => $user->getKey(), 'error' => $exception->getMessage()]);
                    $failed++;
                }
            }
        });

        $this->info("{$sent} reminder email(s) sent.");

        if ($failed > 0) {
            $this->error("{$failed} reminder email(s) could not be sent; see the application log.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
