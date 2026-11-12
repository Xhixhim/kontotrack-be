<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to a person whose KontoTrack account was just created for them, with a link to choose a password.
 */
class AccountInvitation extends Notification
{
    public function __construct(
        private string $token,
        private string $inviterName,
        private ?string $businessName = null,
    ) {}

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
    public function toMail(User $notifiable): MailMessage
    {
        $url = rtrim((string) config('kontotrack.frontend_url'), '/').'/reset-password?'.http_build_query([
            'token' => $this->token,
            'email' => $notifiable->email,
            'invited' => 1,
        ]);
        $minutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire');
        $business = $this->businessName === null ? '' : " ({$this->businessName})";

        return (new MailMessage)
            ->subject('Ftesë në KontoTrack · Invitation to KontoTrack')
            ->greeting("Përshëndetje {$notifiable->name},")
            ->line("{$this->inviterName} ju ka hapur një llogari në KontoTrack{$business}. Vendosni fjalëkalimin tuaj për të hyrë.")
            ->line("{$this->inviterName} created a KontoTrack account for you{$business}. Choose your password to sign in.")
            ->action('Vendos fjalëkalimin · Set your password', $url)
            ->line("Lidhja vlen {$minutes} minuta. Nëse skadon, përdorni \"Keni harruar fjalëkalimin?\" në faqen e hyrjes.")
            ->line("The link is valid for {$minutes} minutes. If it expires, use \"Forgot your password?\" on the sign-in page.");
    }
}
