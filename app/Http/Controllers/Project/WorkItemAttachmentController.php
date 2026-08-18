<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\WorkItem;
use App\Models\WorkItemAttachment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Files attached to a work item (docs/features/work-item-attachments.md) — the paperclip in the
 * detail action row, which shipped inert as "coming soon" until this.
 *
 * Files are stored PRIVATE and streamed through `show()` behind the project's `viewAny` ability,
 * the same shape WorkItemMediaController uses and for the same reason (CLAUDE.md §7): a file on
 * the public disk is a URL that works for anyone who receives it, regardless of workspace.
 *
 * Refusals are 404 rather than 403 throughout, so a response never confirms that a file exists
 * somewhere the caller cannot reach (§12).
 */
class WorkItemAttachmentController extends Controller
{
    /** POST /projects/{project}/work-items/{workItem}/attachments */
    public function store(Request $request, Project $project, WorkItem $workItem): JsonResponse
    {
        abort_unless($workItem->project_id === $project->id, 404);

        // Attaching is a write, gated exactly like editing the item is (§34).
        if (! Auth::user()->can('create', [WorkItem::class, $project])) {
            return $this->failure('You do not have permission to attach files here.', 403);
        }

        $files = collect($request->allFiles())
            ->filter(fn ($file, $key) => str_starts_with((string) $key, 'file'))
            ->flatten();

        if ($files->isEmpty()) {
            return $this->failure('No file was received.');
        }

        $rules = [
            'file' => [
                'required', 'file',
                'mimes:'.implode(',', config('projects.attachments.mimes')),
                'max:'.(int) config('projects.attachments.max_kb'),
            ],
        ];

        $disk = (string) config('filesystems.media_disk', 'local');
        $created = [];

        foreach ($files as $file) {
            $validator = Validator::make(['file' => $file], $rules);
            if ($validator->fails()) {
                // One bad file fails the batch, as it does for media: a half-stored set would
                // leave the author guessing which one did not make it.
                return $this->failure($validator->errors()->first());
            }

            // 'private' is explicit: the `spaces` disk defaults writes to public-read, and
            // inheriting that would publish every attachment to an unauthenticated URL,
            // bypassing the ability check show() enforces.
            try {
                $path = $file->store(
                    "work-item-attachments/{$project->tenant_id}/{$project->id}/{$workItem->id}",
                    ['disk' => $disk, 'visibility' => 'private'],
                );
            } catch (\Throwable $e) {
                Log::error('Work item attachment upload failed', [
                    'disk' => $disk,
                    'bucket' => config("filesystems.disks.{$disk}.bucket"),
                    'project_id' => $project->id,
                    'work_item_id' => $workItem->id,
                    'file' => $file->getClientOriginalName(),
                    'error' => $e->getMessage(),
                ]);

                return $this->failure("Upload failed: could not write to the '{$disk}' disk. ".$e->getMessage());
            }

            if ($path === false) {
                Log::error('Work item attachment upload returned no path', [
                    'disk' => $disk, 'project_id' => $project->id, 'work_item_id' => $workItem->id,
                ]);

                return $this->failure("Upload failed: the '{$disk}' disk rejected the file without an error.");
            }

            /** @var WorkItemAttachment $attachment */
            $attachment = WorkItemAttachment::create([
                'project_id' => $project->id,
                'work_item_id' => $workItem->id,
                'uploaded_by' => Auth::id(),
                // Recorded, not assumed — show() streams from whatever this says.
                'disk' => $disk,
                'path' => $path,
                'name' => $file->getClientOriginalName(),
                'mime' => $file->getMimeType() ?: 'application/octet-stream',
                'size' => $file->getSize() ?: 0,
            ]);

            $created[] = $attachment;
        }

        return response()->json(['result' => $this->list($workItem), 'added' => count($created)]);
    }

    /** GET /projects/{project}/work-items/{workItem}/attachments */
    public function index(Project $project, WorkItem $workItem): JsonResponse
    {
        abort_unless($workItem->project_id === $project->id, 404);
        abort_unless(Auth::user()->can('viewAny', [WorkItem::class, $project]), 404);

        return response()->json(['result' => $this->list($workItem)]);
    }

    /**
     * GET /projects/{project}/work-items/{workItem}/attachments/{attachment} — download one.
     *
     * Reading is gated on seeing the project's work items, not on editing them: an attachment
     * is part of what the item says, so anyone who can read the item can read its files.
     */
    public function show(Project $project, WorkItem $workItem, WorkItemAttachment $attachment): StreamedResponse
    {
        abort_unless($workItem->project_id === $project->id, 404);
        abort_unless($attachment->work_item_id === $workItem->id, 404);
        abort_unless(Auth::user()->can('viewAny', [WorkItem::class, $project]), 404);

        $disk = Storage::disk($attachment->disk);

        if (! $disk->exists($attachment->path)) {
            // A row pointing at a file the disk does not have is a migration that missed
            // something, not a missing attachment. It renders as a silent 404, so say so.
            Log::warning('Work item attachment missing from disk', [
                'disk' => $attachment->disk, 'attachment_id' => $attachment->id, 'path' => $attachment->path,
            ]);
        }

        abort_unless($disk->exists($attachment->path), 404);

        // download(), not response(): these are files, and Content-Disposition: attachment is
        // also the safe default. Serving an upload inline lets a crafted file execute in the
        // app's origin; nothing on the allow-list needs to render in place.
        return $disk->download($attachment->path, $attachment->name, [
            'Content-Type' => $attachment->mime,
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /** DELETE /projects/{project}/work-items/{workItem}/attachments/{attachment} */
    public function destroy(Project $project, WorkItem $workItem, WorkItemAttachment $attachment): JsonResponse
    {
        abort_unless($workItem->project_id === $project->id, 404);
        abort_unless($attachment->work_item_id === $workItem->id, 404);
        abort_unless(Auth::user()->can('create', [WorkItem::class, $project]), 404);

        // The file goes first, but a failure to remove it does NOT stop the row going: an
        // orphaned blob is recoverable, a row pointing at nothing is a permanent broken link.
        try {
            Storage::disk($attachment->disk)->delete($attachment->path);
        } catch (\Throwable $e) {
            Log::error('Work item attachment file delete failed', [
                'disk' => $attachment->disk,
                'attachment_id' => $attachment->id,
                'path' => $attachment->path,
                'error' => $e->getMessage(),
            ]);
        }

        $attachment->delete();

        return response()->json(['result' => $this->list($workItem)]);
    }

    /** @return array<int, array<string, mixed>> */
    private function list(WorkItem $workItem): array
    {
        return WorkItemAttachment::query()
            ->where('work_item_id', $workItem->id)
            ->with('uploader:id,full_name')
            ->oldest('id')
            ->get()
            ->map(fn (WorkItemAttachment $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'size' => $a->size,
                'mime' => $a->mime,
                'url' => $a->url(),
                'uploaded_by' => $a->uploader?->full_name,
                'created_at' => $a->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /** Same envelope the editor's media endpoint uses, so the client has one error shape. */
    private function failure(string $message, int $status = 422): JsonResponse
    {
        return response()->json(['errorMessage' => $message], $status);
    }
}
