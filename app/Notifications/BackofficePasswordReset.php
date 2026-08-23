<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The Back Office password-reset link (docs/features/backoffice-auth.md, §6).
 *
 * Its own notification rather than Laravel's default, for one load-bearing reason: the default
 * builds its URL from the `password.reset` route, which is the CUSTOMER application's. A Back
 * Office administrator following that link would land on the wrong reset form, and the token
 * would not resolve.
 */
class BackofficePasswordReset extends Notification
{
    use Queueable;

    public function __construct(public readonly string $token) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = route('backoffice.password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        $minutes = (int) config('auth.passwords.backoffice_users.expire', 30);

        return (new MailMessage)
            ->subject('Reset your Back Office password')
            ->greeting('Back Office password reset')
            ->line('Somebody asked to reset the password on your Back Office account.')
            ->action('Set a new password', $url)
            ->line("This link expires in {$minutes} minutes and can be used once.")
            // Said plainly, because it is the reassurance that stops somebody clicking a link
            // they did not ask for "just to check".
            ->line('If you did not request this, no action is needed — your password has not changed.');
    }
}
