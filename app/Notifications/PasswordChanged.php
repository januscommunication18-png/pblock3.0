<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Your password was changed" (Account §4).
 *
 * The point of this mail is not to confirm a thing the user just did — they were there. It is
 * to reach the person who did NOT do it: if someone else changed the password, this is the
 * only signal the real owner gets, and it arrives at an address the attacker has not taken
 * over. That is why it is sent after every change, with no preference to switch it off.
 *
 * Queued (CLAUDE.md §11), so a slow mail server cannot hold up the response — the user is
 * being signed out on the other side of it.
 *
 * Mail only, deliberately. §9 makes `database` the Phase 1 baseline channel, but the
 * `notifications` table is not provisioned yet, and an in-app alert would be shown to the
 * session that is about to be logged out anyway. When that table lands, this is the first
 * notification that should gain a database channel.
 */
class PasswordChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly ?string $ip = null) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Your password was changed')
            ->greeting('Hello '.$notifiable->displayName().',')
            ->line('The password for your Project Block account was just changed.')
            ->line('You have been signed out everywhere and will need to sign in again with the new password.');

        if ($this->ip) {
            // The address is context, not proof — it can be a VPN or an office gateway. It is
            // here because "was that me?" is easier to answer with a place attached.
            $mail->line('Request origin: '.$this->ip);
        }

        return $mail
            ->action('Sign in', route('signin'))
            ->line('If this was not you, sign in and change your password immediately, then contact an administrator.');
    }
}
