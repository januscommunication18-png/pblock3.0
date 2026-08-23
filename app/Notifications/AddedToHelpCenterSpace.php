<?php

namespace App\Notifications;

use App\Models\HelpCenterSpace;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "You have been added to a Help Center Space" (docs/features/help-center.md, P10).
 *
 * Somebody already in the workspace gets no invitation email — there is nothing to accept, they
 * are simply on the Space now. Without this they would find out by noticing a new entry in their
 * navigation, which is not a way to learn that customer email is now partly theirs to answer.
 *
 * Queued (CLAUDE.md §11): the person clicking Add Member should not wait on a mail server, and
 * unlike an invitation there is no link here whose absence would strand somebody — a lost
 * notification is a missed heads-up, not a broken flow.
 */
class AddedToHelpCenterSpace extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly HelpCenterSpace $space,
        private readonly string $addedBy,
        /** @var array<int, string> */
        private readonly array $departmentGroups = [],
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('You have been added to '.$this->space->name)
            ->greeting('Hello '.$notifiable->displayName().',')
            ->line($this->addedBy.' added you to the **'.$this->space->name.'** Space in the Help Center.')
            ->line('You can now see its Inbox and work the customer requests that arrive there.');

        if ($this->departmentGroups !== []) {
            $mail->line('Department groups: '.implode(', ', $this->departmentGroups));
        }

        /*
         * `spaces.open`, not `spaces.section`.
         *
         * The section URL resolves the Space out of whatever workspace the reader has active,
         * which is a fair assumption inside the app and a poor one in an email: this is read
         * later, in a mail client, possibly signed out and possibly with another workspace
         * current. `spaces.open` establishes both — sign-in first if there is no session, then
         * the right workspace — and lands on this Space's Overview either way (P10).
         */
        return $mail
            ->action('View Space', route('help-center.spaces.open', ['space' => $this->space->id]))
            ->line('Your Workspace role has not changed.');
    }
}
