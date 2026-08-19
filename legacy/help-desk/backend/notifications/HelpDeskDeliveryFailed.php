<?php

namespace App\Notifications;

use App\Models\HelpDeskConversation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Your reply did not reach the customer" (docs/features/help-desk.md — Phase 2, FR-2.9).
 *
 * The point of this mail is that a bounced reply is otherwise SILENT. The agent believes they
 * answered, the customer never heard, and the case sits there looking handled — which is the
 * single worst failure mode a help desk has. Nothing on any screen would say so until somebody
 * happened to open the conversation.
 *
 * Queued (CLAUDE.md §11): it is triggered by a provider webhook, and a slow mail server must not
 * hold up the response to it.
 *
 * Mail only, deliberately — the same position PasswordChanged records. §9 makes `database` the
 * baseline channel, but the `notifications` table is not provisioned, and the in-app Inbox is
 * built around work items rather than conversations. When the conversation screen lands in
 * Phase 3 this is the first notification that should gain an in-app channel pointing at it.
 */
class HelpDeskDeliveryFailed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly HelpDeskConversation $conversation,
        private readonly string $recipient,
        private readonly ?string $reason,
        private readonly string $workspaceName,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $subject = trim((string) $this->conversation->subject) ?: 'a conversation';

        $mail = (new MailMessage)
            ->subject('Undelivered reply — '.$this->conversation->reference().' '.$subject)
            ->greeting('A reply could not be delivered')
            ->line("Your Help Desk tried to reach {$this->recipient} about {$this->conversation->reference()} “{$subject}” in {$this->workspaceName}, and the mail server refused it.");

        if ($this->reason) {
            // The provider's own words. "550 5.1.1 user unknown" is the whole answer to why,
            // and paraphrasing it removes the part somebody can act on.
            $mail->line('The reason given was: '.$this->reason);
        }

        return $mail
            ->line('The customer has not seen your reply. Check the address and try again, or reach them another way.')
            ->salutation('— Project Block');
    }
}
