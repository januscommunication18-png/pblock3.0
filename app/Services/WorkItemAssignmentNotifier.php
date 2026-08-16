<?php

namespace App\Services;

use App\Mail\WorkItemAssignedMail;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Tells someone they were given a work item (§4.3 / CLAUDE.md §9).
 *
 * Two rules keep the mail meaningful rather than noisy:
 *  - only the *newly* assigned user is told, not whoever was assigned before;
 *  - assigning something to yourself sends nothing — you already know.
 *
 * Sending is registered with `DB::afterCommit`, because assignment happens inside the
 * updater's transaction: if that transaction rolls back, the assignment never happened and
 * the mail must not go out. Delivery problems are logged, never thrown — a mail server being
 * down must not fail the edit that triggered it.
 *
 * Sent IMMEDIATELY (`sendNow`) rather than queued, by product decision — a departure from
 * CLAUDE.md §11. Queued, the mail waits for a worker and never arrives at all where none is
 * running; the price of not queueing is that the assigning request waits for the SMTP
 * round-trip.
 */
class WorkItemAssignmentNotifier
{
    public function assigned(WorkItem $item, User $assignee, ?User $actor): void
    {
        if ($actor && $actor->id === $assignee->id) {
            return;
        }

        $item->loadMissing('project');
        $project = $item->project;

        if (! $project || ! filter_var($assignee->email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $mail = new WorkItemAssignedMail(
            identifier: (string) $item->identifier,
            title: (string) $item->title,
            projectName: (string) $project->name,
            assignerName: $actor?->displayName() ?? 'Someone',
            assigneeName: $assignee->displayName(),
            url: route('projects.work-items.show', [
                'project' => $project->id,
                'workItem' => $item->id,
            ]),
            description: app(RichTextSanitizer::class)->excerpt(
                $item->description,
                (int) config('projects.excerpt.email'),
            ),
        );

        $email = $assignee->email;

        DB::afterCommit(function () use ($email, $mail, $item, $assignee) {
            try {
                Mail::to($email)->sendNow($mail);
            } catch (\Throwable $e) {
                Log::error('work_item.assignment.email_failed', [
                    'work_item_id' => $item->id,
                    'assignee_id' => $assignee->id,
                    'reason' => $e->getMessage(),
                ]);
            }
        });
    }
}
