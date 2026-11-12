<?php

namespace App\Notifications;

use App\Support\DocumentFormat;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One email listing what needs the user's attention across their businesses: deadlines, document requests, approvals.
 */
class ReminderDigest extends Notification
{
    /** @param list<array{type: string, severity: string, business_id: int, business_name: string, title: string, due_on: string|null, task_id: int|null}> $reminders */
    public function __construct(private array $reminders) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $overdue = count(array_filter($this->reminders, fn (array $reminder): bool => $reminder['severity'] === 'critical'));
        $count = count($this->reminders);
        $mail = (new MailMessage)
            ->subject($overdue > 0 ? "KontoTrack: {$overdue} me vonesë · {$overdue} overdue" : "KontoTrack: {$count} kujtesa · {$count} reminder(s)")
            ->greeting("Përshëndetje {$notifiable->name},")
            ->line('Këto kërkojnë vëmendjen tuaj në KontoTrack. · These need your attention in KontoTrack.');

        foreach (collect($this->reminders)->groupBy('business_name') as $businessName => $reminders) {
            $mail->line("**{$businessName}**");

            foreach ($reminders as $reminder) {
                $due = $reminder['due_on'] === null ? '' : ' – '.DocumentFormat::date($reminder['due_on']);
                $flag = $reminder['severity'] === 'critical' ? ' (me vonesë · overdue)' : '';
                $mail->line("• {$reminder['title']}{$due}{$flag}");
            }
        }

        return $mail
            ->action('Hap KontoTrack · Open KontoTrack', rtrim((string) config('kontotrack.frontend_url'), '/').'/')
            ->line('Këto email-e mund t\'i ndalni te cilësimet e llogarisë. · You can turn these emails off in your account settings.');
    }
}
