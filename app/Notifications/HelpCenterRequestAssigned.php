<?php

namespace App\Notifications;

use App\Models\HelpCenterMessage;
use App\Models\HelpCenterRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "A new Request has been assigned to you" (docs/features/help-center.md, P26).
 *
 * Auto-assignment without this is a silent hand-off: the Request lands in somebody's Mine queue
 * and stays there until they happen to look. The whole point of naming default assignees on a
 * status is that the work reaches a person, and reaching a person means telling them.
 *
 * Queued (CLAUDE.md §11), and this one matters more than most: it is sent from the inbound
 * ingest job, which Postmark is waiting on nothing for — but a mail server hiccup must not fail
 * a job whose real work (the Request itself) is already committed.
 */
class HelpCenterRequestAssigned extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly HelpCenterRequest $request) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        /*
         * Mail only, for now.
         *
         * CLAUDE.md §9 makes database and broadcast the Phase 1 default, and this notification
         * should carry both the moment the Help Center has a bell to ring — the payload below is
         * already the shape §9 asks for. It does not yet, so a database row nothing renders and
         * a broadcast nothing listens to would be two channels that only look like they work.
         */
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $space = $this->request->space;
        $customer = $this->request->customerLabel();

        $mail = (new MailMessage)
            ->subject('New Request assigned to you — '.$this->request->ticketNumber())
            ->greeting('Hello '.$notifiable->displayName().',')
            ->line('A new customer Request has been assigned to you automatically, because you are '
                .'a default assignee for this workflow status.')
            ->line('**'.$this->request->ticketNumber().' — '.($this->request->subject ?: '(no subject)').'**')
            ->line('From: '.$customer.' <'.$this->request->customer_email.'>')
            ->line('Space: '.($space?->name ?? '—'))
            ->line('Received: '.($this->request->last_message_at?->format('M j, Y \a\t g:i A') ?? '—'))
            /*
             * The customer's own words, not just the subject line.
             *
             * The point of this mail is that somebody can decide whether to stop what they are
             * doing, and a subject alone rarely settles that — "Invoice question" could be a
             * typo or a chargeback. Reading the message here is what makes the mail worth
             * opening rather than a prompt to go and open something else.
             */
            ->line('---');

        /*
         * One `line()` per PARAGRAPH, not one for the whole message.
         *
         * A MailMessage renders each line inside a single `<p>`, so passing the body as one
         * string collapsed every break in it: a customer's greeting, their problem, their
         * question and their sign-off arrived as one run-on sentence.
         *
         * A `>` blockquote prefix was tried and dropped: the mail template escapes each line
         * before the markdown is parsed, so it arrived as a literal ">" down the left of every
         * paragraph. The `---` rule above is what separates our words from the customer's.
         */
        foreach ($this->bodyParagraphs() as $paragraph) {
            $mail->line($paragraph);
        }

        return $mail
            /*
             * `spaces.open`, not a Space section URL — the same reasoning as
             * AddedToHelpCenterSpace. This is read later, in a mail client, possibly signed out
             * and possibly with another workspace active; that route establishes the session and
             * the workspace before landing in the Space.
             *
             * It opens the SPACE rather than the Request, because a Request has no detail screen
             * yet. A link into a page that does not exist is worse than a link one click away
             * from the queue the Request is sitting in.
             */
            ->action('Open '.($space?->name ?? 'the Space'), route('help-center.spaces.open', [
                'space' => $this->request->help_center_space_id,
            ]))
            ->line('It is waiting in your Mine queue.');
    }

    /**
     * The message the customer actually sent, trimmed for an email.
     *
     * The TEXT part first and HTML stripped only as a fallback — the same order the ingestor
     * builds its preview in, and for the same reason: the text part is what the sender wrote,
     * while a stripped HTML part carries whatever hidden preheader text their client injected.
     *
     * Capped at 2000 characters. This is a heads-up, not an archive: a forwarded newsletter or a
     * thread with a long quoted history should not arrive as a wall of text in somebody's inbox,
     * and the full Request is one click away. `preview` is the fallback when there is no message
     * row to read — a Request opened by some path that stored none.
     */
    /** @return array<int, string> */
    private function bodyParagraphs(): array
    {
        $text = $this->body();

        /*
         * Split on blank lines. Single newlines stay collapsed, which is what a mail client does
         * to a plain-text part anyway — the breaks that carry meaning are the blank ones.
         */
        $paragraphs = array_values(array_filter(array_map(
            fn (string $p) => trim((string) preg_replace('/\s*\n\s*/', ' ', $p)),
            preg_split("/\n{2,}/", $text) ?: [],
        ), fn (string $p) => $p !== ''));

        return $paragraphs === [] ? [$text] : $paragraphs;
    }

    private function body(): string
    {
        $message = $this->request->messages()
            ->where('direction', HelpCenterMessage::DIRECTION_INBOUND)
            ->orderBy('id')
            ->first();

        $text = trim((string) ($message?->body_text ?? ''));

        if ($text === '' && $message !== null) {
            $text = trim(html_entity_decode(strip_tags((string) $message->body_html), ENT_QUOTES | ENT_HTML5));
        }

        if ($text === '') {
            $text = trim((string) $this->request->preview);
        }

        if ($text === '') {
            return '_The message had no readable body._';
        }

        // Collapse runs of blank lines: quoted replies arrive with a great many of them, and a
        // markdown mail renders each one as another empty paragraph.
        $text = (string) preg_replace("/\n{3,}/", "\n\n", str_replace(["\r\n", "\r"], "\n", $text));

        return mb_strlen($text) > 2000
            ? mb_substr($text, 0, 2000).'…'."\n\n".'_Message truncated — open the Space to read the rest._'
            : $text;
    }

    /**
     * The §9 payload, ready for the database and broadcast channels.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'New Request assigned to you',
            'message' => $this->request->ticketNumber().' — '.($this->request->subject ?: '(no subject)'),
            'module' => 'help-center',
            'entity_type' => 'help_center_request',
            'entity_id' => $this->request->id,
            'priority' => $this->request->priority,
            'action_url' => route('help-center.spaces.open', [
                'space' => $this->request->help_center_space_id,
            ]),
        ];
    }
}
