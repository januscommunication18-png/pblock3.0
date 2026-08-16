<?php

namespace App\Services;

use App\Mail\MentionedMail;
use App\Models\Mention;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkItemComment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Tells people they were named (mentions §15, §19, §20).
 *
 * Takes the list MentionSync produced — people NEWLY mentioned in this piece of content — and
 * applies the one rule that is about notification rather than about the record:
 *
 *  §15 — **you are never told you mentioned yourself.** The mention is still recorded, because
 *  the content genuinely names you and the Inbox will want to render it; the email is what
 *  would be absurd.
 *
 * Everything else — deduplication (§14), only-new-on-edit (§11/§12), permission (§16/§24) — is
 * already settled by the time the list arrives here. This class does delivery and nothing else,
 * which is what §31 asks for: the notification path must not be coupled to the editor.
 *
 * Sent after commit and never fatally: a mention is written inside the transaction that saves
 * the comment or the work item, and a mail server being down must not fail somebody's edit.
 */
class MentionNotifier
{
    public function __construct(
        private readonly RichTextSanitizer $richText,
        private readonly InboxNotifier $inbox,
    ) {}

    /**
     * @param  Collection<int, User>  $users  newly mentioned, from MentionSync::sync()
     */
    public function mentioned(
        Collection $users,
        WorkItem $item,
        ?User $actor,
        string $sourceType,
        ?string $html,
        ?int $commentId = null,
        ?WorkItemComment $comment = null,
    ): void {
        // The Inbox is the in-app record and is written ALWAYS (§36): turning email off must
        // not turn the Inbox off, so this happens before — and independently of — delivery.
        $this->inbox->mentioned($users, $item, $actor, $sourceType, $html, $comment);

        $recipients = $users
            // §15: not yourself.
            ->reject(fn (User $user) => $actor && (int) $user->id === (int) $actor->id)
            ->filter(fn (User $user) => filter_var($user->email, FILTER_VALIDATE_EMAIL));

        if ($recipients->isEmpty()) {
            return;
        }

        $item->loadMissing('project');
        $project = $item->project;

        if (! $project) {
            return;
        }

        // §20: back to the exact source. The comment id rides in the fragment so the item page
        // can scroll to and highlight it — the same shape the Inbox will use when it lands.
        $url = route('projects.work-items.show', ['project' => $project->id, 'workItem' => $item->id]);
        if ($commentId) {
            $url .= '?tab=comments#comment-'.$commentId;
        }

        $mail = new MentionedMail(
            identifier: (string) $item->identifier,
            title: (string) $item->title,
            projectName: (string) $project->name,
            actorName: $actor?->displayName() ?? 'Someone',
            where: $sourceType === Mention::SOURCE_COMMENT ? 'a comment' : 'the description',
            excerpt: (string) $this->richText->excerpt($html, (int) config('projects.excerpt.email')),
            url: $url,
            // Only for a COMMENT mention: when the mention is in the description, `excerpt`
            // already is the description, and printing it twice under two headings would
            // read as a bug rather than as context.
            description: $sourceType === Mention::SOURCE_COMMENT
                ? $this->richText->excerpt($item->description, (int) config('projects.excerpt.email'))
                : null,
        );

        $emails = $recipients->pluck('email')->all();
        $itemId = $item->id;

        DB::afterCommit(function () use ($emails, $mail, $itemId) {
            foreach ($emails as $email) {
                try {
                    Mail::to($email)->sendNow($mail);
                } catch (\Throwable $e) {
                    Log::error('mention.email_failed', [
                        'work_item_id' => $itemId,
                        'email' => $email,
                        'reason' => $e->getMessage(),
                    ]);
                }
            }
        });
    }
}
