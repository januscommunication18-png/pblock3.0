<?php

namespace App\Services;

use App\Mail\WorkItemBlockedMail;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Tells the people responsible for a work item that it has just become blocked
 * (Collaboration spec §58).
 *
 * WHO IS TOLD: the project's lead (its owner) and the work item's assignee. Those are the two
 * people who have to do something about a blocker — the lead because they own delivery, the
 * assignee because it is their work that has stopped. Duplicates are collapsed, and whoever
 * created the dependency is not mailed about their own action.
 *
 * WHEN: only when a `blocking` row is created, and only for the item on the receiving end —
 * the item doing the blocking has not changed state as far as its owner is concerned.
 *
 * Registered with `DB::afterCommit`, because relations are written inside a transaction: if
 * it rolls back, nothing is blocked and no mail should claim otherwise.
 */
class WorkItemBlockedNotifier
{
    /**
     * @param  array<int, WorkItem>  $blockers  the work items now blocking `$item`
     */
    public function blocked(WorkItem $item, array $blockers, ?User $actor): void
    {
        if ($blockers === []) {
            return;
        }

        $item->loadMissing(['project.lead', 'assignees']);
        $project = $item->project;

        if (! $project) {
            return;
        }

        $recipients = collect([$project->lead])
            ->merge($item->assignees)
            ->filter()
            ->reject(fn (User $u) => $actor && $u->id === $actor->id)
            ->unique('id')
            ->filter(fn (User $u) => filter_var($u->email, FILTER_VALIDATE_EMAIL));

        if ($recipients->isEmpty()) {
            return;
        }

        $mail = new WorkItemBlockedMail(
            identifier: (string) $item->identifier,
            title: (string) $item->title,
            projectName: (string) $project->name,
            actorName: $actor?->displayName() ?? 'Someone',
            blockers: collect($blockers)->map(fn (WorkItem $b) => [
                'identifier' => (string) $b->identifier,
                'title' => (string) $b->title,
            ])->values()->all(),
            url: route('projects.work-items.show', [
                'project' => $project->id,
                'workItem' => $item->id,
            ]),
        );

        $emails = $recipients->pluck('email')->all();

        DB::afterCommit(function () use ($emails, $mail, $item) {
            foreach ($emails as $email) {
                try {
                    Mail::to($email)->queue($mail);
                } catch (\Throwable $e) {
                    Log::error('work_item.blocked.email_failed', [
                        'work_item_id' => $item->id,
                        'reason' => $e->getMessage(),
                    ]);
                }
            }
        });
    }
}
