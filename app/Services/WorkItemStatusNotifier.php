<?php

namespace App\Services;

use App\Mail\WorkItemStatusChangedMail;
use App\Models\ProjectItemState;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkItemSubscriber;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Tells a work item's subscribers that its status moved (CLAUDE.md §9).
 *
 * Built on the same three rules as WorkItemAssignmentNotifier, because they are what make an
 * alert worth reading rather than something people filter away:
 *
 *  - **Only subscribers.** Not assignees, not the project — the person asked for this item.
 *  - **Never the person who did it.** You do not need telling what you just clicked.
 *  - **After commit, sent immediately, and never fatal.** The state change happens inside the
 *    updater's transaction: if that rolls back, the status never moved and the mail must not
 *    go out. A mail server being down must not fail somebody's edit, so failures are logged.
 *
 * DELIVERY IS IMMEDIATE (`sendNow`), by product decision, and this is a deliberate departure
 * from CLAUDE.md §11's "queue email notifications". Queueing means the alert waits for a
 * worker — and on any box where nobody is running `queue:work`, it never arrives at all. The
 * cost is real and worth knowing: the request that changed the status pays for one SMTP
 * round-trip per subscriber before it returns. If that ever becomes the slow part of a chip
 * click, the fix is a real queue plus a worker, not a smaller recipient list.
 *
 * Each subscriber is checked against WorkItemPolicy@view before being written to. Subscribing
 * is not a permanent grant: someone removed from a project keeps their subscription row, and
 * without this check they would keep receiving that project's work by email (requirements §7).
 */
class WorkItemStatusNotifier
{
    public function statusChanged(WorkItem $item, ?User $actor, ?int $fromStateId, ?int $toStateId): void
    {
        if ($fromStateId === $toStateId) {
            return;
        }

        $item->loadMissing('project');
        $project = $item->project;

        if (! $project) {
            return;
        }

        $recipients = $this->recipients($item, $actor);

        if ($recipients->isEmpty()) {
            return;
        }

        // Resolved to names NOW, while there is still a tenancy context — a queued mailable is
        // rebuilt by a worker that has none, and a state id would not resolve there. It also
        // freezes the wording: renaming a state later must not rewrite an email already sent.
        $mail = new WorkItemStatusChangedMail(
            identifier: (string) $item->identifier,
            title: (string) $item->title,
            projectName: (string) $project->name,
            actorName: $actor?->displayName() ?? 'Someone',
            fromState: $fromStateId ? ProjectItemState::find($fromStateId)?->name : null,
            toState: $toStateId ? ProjectItemState::find($toStateId)?->name : null,
            url: route('projects.work-items.show', [
                'project' => $project->id,
                'workItem' => $item->id,
            ]),
        );

        $emails = $recipients->pluck('email')->all();
        $itemId = $item->id;

        DB::afterCommit(function () use ($emails, $mail, $itemId) {
            foreach ($emails as $email) {
                try {
                    Mail::to($email)->sendNow($mail);
                } catch (\Throwable $e) {
                    Log::error('work_item.status.email_failed', [
                        'work_item_id' => $itemId,
                        'email' => $email,
                        'reason' => $e->getMessage(),
                    ]);
                }
            }
        });
    }

    /**
     * Who should hear about this: subscribers who can still see the item, minus whoever
     * changed it, minus anyone without a usable address.
     *
     * @return Collection<int, User>
     */
    private function recipients(WorkItem $item, ?User $actor)
    {
        $userIds = WorkItemSubscriber::query()
            ->where('work_item_id', $item->id)
            ->when($actor, fn ($q) => $q->where('user_id', '!=', $actor->id))
            ->pluck('user_id');

        if ($userIds->isEmpty()) {
            return collect();
        }

        return User::whereIn('id', $userIds)->get()
            ->filter(fn (User $user) => filter_var($user->email, FILTER_VALIDATE_EMAIL)
                && $user->can('view', $item));
    }
}
