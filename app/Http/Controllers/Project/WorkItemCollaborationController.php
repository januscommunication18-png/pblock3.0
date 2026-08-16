<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\Mention;
use App\Models\Project;
use App\Models\WorkItem;
use App\Models\WorkItemActivity;
use App\Models\WorkItemComment;
use App\Models\WorkItemUpdate;
use App\Models\WorkItemWorklog;
use App\Services\InboxNotifier;
use App\Services\MentionNotifier;
use App\Services\MentionSync;
use App\Services\RichTextSanitizer;
use App\Services\WorkItemActivityRecorder;
use App\Services\WorkItemFeedBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Comments, Updates and Worklogs on a work item, plus the reads behind all seven tabs
 * (Activity & Audit spec §5–§11, §15).
 *
 * Every write returns the whole refreshed feed, for the same reason the structure endpoints
 * do: the panel shows seven views of one dataset, and reconciling a partial response into
 * six of them is how they end up disagreeing.
 *
 * Authorship rules are enforced here, not in the UI (§20): a user may edit and delete their
 * OWN comment; project managers may remove anyone's. Activity, history and transitions have
 * no write endpoint at all — they are produced by the services that perform the change
 * (§6.5/§11.9/§22.4).
 */
class WorkItemCollaborationController extends Controller
{
    public function __construct(
        private readonly WorkItemFeedBuilder $feed,
        private readonly RichTextSanitizer $richText,
        private readonly WorkItemActivityRecorder $activity,
        private readonly MentionSync $mentions,
        private readonly MentionNotifier $mentionNotifier,
    ) {}

    /** GET /projects/{project}/work-items/{workItem}/feed */
    public function show(Project $project, WorkItem $workItem): JsonResponse
    {
        $this->guard($project, $workItem, 'view');

        return $this->payload($workItem);
    }

    // ------------------------------------------------------------------ comments (§7)

    public function storeComment(Request $request, Project $project, WorkItem $workItem): JsonResponse
    {
        $this->guard($project, $workItem, 'update');

        $data = $request->validate([
            'content' => ['required', 'string', 'max:'.config('projects.work_item_description_max')],
            // §7.8: replies attach to a top-level comment, and only one level deep.
            'parent_comment_id' => ['nullable', 'integer'],
        ]);

        $content = $this->richText->sanitize($data['content']);
        if ($content === null) {
            return response()->json(['message' => 'A comment cannot be empty.'], 422);
        }

        $parentId = $this->resolveParent($workItem, $data['parent_comment_id'] ?? null);

        DB::transaction(function () use ($workItem, $content, $parentId) {
            /** @var WorkItemComment $comment */
            $comment = WorkItemComment::create([
                'project_id' => $workItem->project_id,
                'work_item_id' => $workItem->id,
                'parent_comment_id' => $parentId,
                'author_id' => Auth::id(),
                'content' => $content,
            ]);

            // §13.3: a comment is an event in the story, but it changes no work item
            // property — so it gets an activity row and nothing in History.
            $this->activity->record($workItem, Auth::user(), WorkItemActivity::EVENT_UPDATED, [
                'field' => $parentId ? 'comment_reply' : 'comment',
            ]);

            // §9: mentions are processed after the comment is saved and has an id — the
            // notification needs somewhere to point. Inside the transaction, so a failed
            // comment leaves no mention records; the mail waits for the commit.
            $workItem->loadMissing('project');
            if ($workItem->project) {
                $this->mentionNotifier->mentioned(
                    $this->mentions->sync(Mention::SOURCE_COMMENT, $comment->id, $content, $workItem->project, $workItem, Auth::user()),
                    $workItem, Auth::user(), Mention::SOURCE_COMMENT, $content, $comment->id, $comment,
                );
            }
        });

        return $this->payload($workItem, 'Comment added.');
    }

    public function updateComment(Request $request, Project $project, WorkItem $workItem, WorkItemComment $comment): JsonResponse
    {
        $this->guard($project, $workItem, 'update');
        $this->guardComment($workItem, $comment, owner: true);

        $content = $this->richText->sanitize($request->input('content'));
        if ($content === null) {
            return response()->json(['message' => 'A comment cannot be empty.'], 422);
        }

        $comment->forceFill(['content' => $content, 'edited_at' => now()])->save();

        // §12: editing tells only the people newly named — whoever was already mentioned has
        // already heard, and telling them again on every edit is how a mention becomes noise.
        $workItem->loadMissing('project');
        if ($workItem->project) {
            $this->mentionNotifier->mentioned(
                $this->mentions->sync(Mention::SOURCE_COMMENT, $comment->id, $content, $workItem->project, $workItem, Auth::user()),
                $workItem, Auth::user(), Mention::SOURCE_COMMENT, $content, $comment->id, $comment,
            );
        }

        return $this->payload($workItem, 'Comment updated.');
    }

    public function destroyComment(Project $project, WorkItem $workItem, WorkItemComment $comment): JsonResponse
    {
        $this->guard($project, $workItem, 'update');
        $this->guardComment($workItem, $comment, owner: false);

        // Inbox §19: the unread notifications that pointed into this comment go with it —
        // there is nothing left to open. Read ones stay: they are a record of what happened.
        app(InboxNotifier::class)->commentDeleted($comment);

        // Soft delete (§7.7): the conversation loses it, the audit trail keeps it.
        $comment->delete();

        return $this->payload($workItem, 'Comment deleted.');
    }

    // ------------------------------------------------------------------- updates (§8)

    public function storeUpdate(Request $request, Project $project, WorkItem $workItem): JsonResponse
    {
        $this->guard($project, $workItem, 'update');

        $data = $request->validate([
            'status' => ['required', Rule::in(WorkItemUpdate::STATUSES)],
            'content' => ['required', 'string', 'max:'.config('projects.work_item_description_max')],
        ]);

        $content = $this->richText->sanitize($data['content']);
        if ($content === null) {
            return response()->json(['message' => 'An update needs a message.'], 422);
        }

        // §8.6: freeze progress as it stands now. An update is a statement about that moment.
        $snapshot = $this->feed->progressSnapshot($workItem);

        DB::transaction(function () use ($workItem, $data, $content, $snapshot) {
            $update = WorkItemUpdate::create([
                'project_id' => $workItem->project_id,
                'work_item_id' => $workItem->id,
                'author_id' => Auth::id(),
                'status' => $data['status'],
                'content' => $content,
                'progress_percent' => $snapshot['percent'] ?? null,
                'completed_subtasks' => $snapshot['completed'] ?? null,
                'total_subtasks' => $snapshot['total'] ?? null,
            ]);

            $this->activity->record($workItem, Auth::user(), WorkItemActivity::EVENT_UPDATED, [
                'field' => 'update',
                'new_value' => $update->label(),
            ]);
        });

        return $this->payload($workItem, 'Update added.');
    }

    public function updateUpdate(Request $request, Project $project, WorkItem $workItem, WorkItemUpdate $update): JsonResponse
    {
        $this->guard($project, $workItem, 'update');
        abort_unless((int) $update->work_item_id === (int) $workItem->id, 404);
        abort_unless($this->ownsOrManages($update->author_id, $workItem), 403);

        $data = $request->validate([
            'status' => ['required', Rule::in(WorkItemUpdate::STATUSES)],
            'content' => ['required', 'string', 'max:'.config('projects.work_item_description_max')],
        ]);

        $content = $this->richText->sanitize($data['content']);
        if ($content === null) {
            return response()->json(['message' => 'An update needs a message.'], 422);
        }

        $update->forceFill([
            'status' => $data['status'],
            'content' => $content,
            'edited_at' => now(),
        ])->save();

        return $this->payload($workItem, 'Update saved.');
    }

    public function destroyUpdate(Project $project, WorkItem $workItem, WorkItemUpdate $update): JsonResponse
    {
        $this->guard($project, $workItem, 'update');
        abort_unless((int) $update->work_item_id === (int) $workItem->id, 404);
        abort_unless($this->ownsOrManages($update->author_id, $workItem), 403);

        $update->delete();

        return $this->payload($workItem, 'Update deleted.');
    }

    // ------------------------------------------------------------------ worklogs (§9)

    public function storeWorklog(Request $request, Project $project, WorkItem $workItem): JsonResponse
    {
        $this->guard($project, $workItem, 'update');

        $data = $this->validateWorklog($request);
        $this->assertMayLogWork($workItem, $data['user_id']);
        $this->assertDateWithinItem($workItem, $data['work_date']);

        DB::transaction(function () use ($workItem, $data) {
            $log = WorkItemWorklog::create([
                'project_id' => $workItem->project_id,
                'work_item_id' => $workItem->id,
                'user_id' => $data['user_id'],
                'created_by' => Auth::id(),
                'work_date' => $data['work_date'],
                'minutes_logged' => $data['minutes'],
                'description' => $data['description'],
            ]);

            $this->activity->record($workItem, Auth::user(), WorkItemActivity::EVENT_UPDATED, [
                'field' => 'worklog',
                'new_value' => WorkItemWorklog::humanDuration($log->minutes_logged),
            ]);
        });

        return $this->payload($workItem, 'Work logged.');
    }

    public function updateWorklog(Request $request, Project $project, WorkItem $workItem, WorkItemWorklog $worklog): JsonResponse
    {
        $this->guard($project, $workItem, 'update');
        abort_unless((int) $worklog->work_item_id === (int) $workItem->id, 404);
        abort_unless($this->ownsOrManages($worklog->user_id, $workItem), 403);

        $data = $this->validateWorklog($request);
        $this->assertDateWithinItem($workItem, $data['work_date']);

        $worklog->forceFill([
            'work_date' => $data['work_date'],
            'minutes_logged' => $data['minutes'],
            'description' => $data['description'],
        ])->save();

        return $this->payload($workItem, 'Worklog updated.');
    }

    public function destroyWorklog(Project $project, WorkItem $workItem, WorkItemWorklog $worklog): JsonResponse
    {
        $this->guard($project, $workItem, 'update');
        abort_unless((int) $worklog->work_item_id === (int) $workItem->id, 404);
        abort_unless($this->ownsOrManages($worklog->user_id, $workItem), 403);

        $worklog->delete();

        return $this->payload($workItem, 'Worklog deleted.');
    }

    // ------------------------------------------------------------------------ helpers

    /**
     * §9.5: hours and minutes are a display format; what is stored is a single minute count,
     * and it has to be greater than zero — "logged 0m" is not a record of anything.
     *
     * @return array{work_date:string, minutes:int, description:?string, user_id:int}
     */
    private function validateWorklog(Request $request): array
    {
        $data = $request->validate([
            'work_date' => ['required', 'date'],
            'hours' => ['nullable', 'integer', 'min:0', 'max:99'],
            'minutes' => ['nullable', 'integer', 'min:0', 'max:59'],
            'description' => ['nullable', 'string', 'max:2000'],
            'user_id' => ['nullable', 'integer'],
        ]);

        $total = ((int) ($data['hours'] ?? 0)) * 60 + ((int) ($data['minutes'] ?? 0));

        if ($total <= 0) {
            abort(response()->json([
                'message' => 'Log at least one minute.',
                'errors' => ['minutes' => ['Log at least one minute.']],
            ], 422));
        }

        return [
            'work_date' => $data['work_date'],
            'minutes' => $total,
            'description' => $data['description'] ?? null,
            // Taken at face value, and judged by assertMayLogWork.
            //
            // This used to silently rewrite a non-manager's `user_id` to their own, so asking
            // to log Sarah's time quietly recorded YOURS instead — a 200, a row against the
            // wrong person, and nothing anywhere saying so. One place decides who time may be
            // logged against; this one only reports what was asked for.
            'user_id' => (int) ($data['user_id'] ?? Auth::id()),
        ];
    }

    /** §7.8: replies hang off a top-level comment on THIS work item, one level only. */
    private function resolveParent(WorkItem $item, ?int $parentId): ?int
    {
        if (! $parentId) {
            return null;
        }

        $parent = WorkItemComment::query()
            ->where('work_item_id', $item->id)
            ->whereKey($parentId)
            ->first();

        abort_unless($parent, 404);

        // Replying to a reply attaches to its parent instead of nesting deeper.
        return $parent->parent_comment_id ?? $parent->id;
    }

    /**
     * Editing is the author's alone; deleting is the author's or a project manager's (§7.5).
     */
    private function guardComment(WorkItem $item, WorkItemComment $comment, bool $owner): void
    {
        abort_unless((int) $comment->work_item_id === (int) $item->id, 404);

        if ($owner) {
            abort_unless((int) $comment->author_id === (int) Auth::id(), 403);

            return;
        }

        abort_unless($this->ownsOrManages($comment->author_id, $item), 403);
    }

    /**
     * Who may put hours on this work item (§9.4).
     *
     * Time is a record of who did the work, so it can only be logged against somebody the work
     * is actually assigned to — otherwise a capacity report reads hours against people who were
     * never on the item, and "who is overloaded?" stops meaning anything.
     *
     * Two ways through: you are that assignee, or you run the project and are recording it on
     * their behalf. Enforced HERE rather than by hiding the button, because the endpoint is
     * reachable without it.
     */
    private function assertMayLogWork(WorkItem $item, int $targetUserId): void
    {
        $assignees = $item->assignees()->pluck('users.id')->map(fn ($id) => (int) $id)->all();

        abort_if(
            $assignees === [],
            422,
            'Assign this work item before logging time against it.',
        );

        abort_unless(
            in_array($targetUserId, $assignees, true),
            403,
            'Time can only be logged against someone this work item is assigned to.',
        );

        abort_unless(
            $targetUserId === (int) Auth::id() || Auth::user()->can('manage', $item->project),
            403,
            'Only a project lead can log time on behalf of someone else.',
        );
    }

    /**
     * A worklog cannot predate the work item's own start date.
     *
     * Enforced here and not only in the picker: a date restriction that lives in the calendar
     * is one `curl` away from being ignored, and the hours would land in a week the item did
     * not exist in — which is exactly the sort of figure a capacity report cannot explain.
     *
     * Inclusive of the start date itself: work done on day one is ordinary.
     */
    private function assertDateWithinItem(WorkItem $item, string $workDate): void
    {
        if (! $item->start_date) {
            return;
        }

        abort_if(
            $workDate < $item->start_date->format('Y-m-d'),
            422,
            'Work cannot be logged before this work item starts ('.$item->start_date->format('M j, Y').').',
        );
    }

    private function ownsOrManages(?int $authorId, WorkItem $item): bool
    {
        return (int) $authorId === (int) Auth::id()
            || Auth::user()->can('manage', $item->project);
    }

    private function payload(WorkItem $item, ?string $message = null): JsonResponse
    {
        return response()->json(array_filter([
            'ok' => true,
            'feed' => $this->feed->for($item),
            'message' => $message,
        ], fn ($v) => $v !== null));
    }

    /** §21: the item must belong to this project, and the user must hold the ability. */
    private function guard(Project $project, WorkItem $item, string $ability): void
    {
        abort_unless($item->project_id === $project->id, 404);
        abort_unless(Auth::user()->can($ability, $item), $ability === 'view' ? 404 : 403);
    }
}
