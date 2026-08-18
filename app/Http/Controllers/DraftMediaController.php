<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\WorkItem;
use App\Models\WorkItemMedia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Images for a DRAFT's description editor (docs/features/drafts.md).
 *
 * The workspace-level twin of WorkItemMediaController, and deliberately the same request and
 * response shapes — `file-N` in, `{"result":[{url,name,size}]}` or `{"errorMessage":"…"}` out —
 * because those are the editor's contract, not ours. Both editors already speak it.
 *
 * What differs is what a file can be scoped to. A draft has no project, so neither has its
 * image: the row is tenant-scoped and project-less, and is readable only by the person who
 * uploaded it, which is the same rule the draft itself follows. Publishing re-homes the images
 * the description actually references onto the target project, and from that point they are
 * readable by everyone who can see that project's work items — see WorkItemCreator::publish().
 *
 * Files live on the PRIVATE disk and are streamed through show(). The public disk would hand
 * out a URL that works for anyone who received it, regardless of workspace (CLAUDE.md §7) —
 * and these are images from somebody's private scratch pad.
 */
class DraftMediaController extends Controller
{
    /** POST /drafts/media — the editor's image upload target. */
    public function store(Request $request): JsonResponse
    {
        if (! Auth::user()->can('createDraft', WorkItem::class)) {
            return $this->failure('You do not have permission to upload images here.', 403);
        }

        $files = collect($request->allFiles())
            ->filter(fn ($file, $key) => str_starts_with((string) $key, 'file-'))
            ->flatten();

        if ($files->isEmpty()) {
            return $this->failure('No image was received.');
        }

        $rules = [
            'file' => [
                'required', 'file', 'image',
                'mimes:'.implode(',', config('projects.media.mimes')),
                'max:'.(int) config('projects.media.max_kb'),
            ],
        ];

        $result = [];
        $tenantId = Auth::user()->current_workspace_id;

        foreach ($files as $file) {
            $validator = Validator::make(['file' => $file], $rules);
            if ($validator->fails()) {
                // One bad file fails the batch, as it does for work items: a half-inserted set
                // of images would leave the author guessing which one did not make it.
                return $this->failure($validator->errors()->first());
            }

            $disk = (string) config('filesystems.media_disk', 'local');

            // Filed under the uploader rather than a project, because there is no project yet.
            // 'private' is explicit for the same reason as the work-item path: the `spaces`
            // disk would otherwise default these to public-read.
            try {
                $path = $file->store(
                    "work-item-media/{$tenantId}/drafts/".Auth::id(),
                    ['disk' => $disk, 'visibility' => 'private'],
                );
            } catch (\Throwable $e) {
                Log::error('Draft media upload failed', [
                    'disk' => $disk,
                    'bucket' => config("filesystems.disks.{$disk}.bucket"),
                    'user_id' => Auth::id(),
                    'file' => $file->getClientOriginalName(),
                    'error' => $e->getMessage(),
                ]);

                return $this->failure("Upload failed: could not write to the '{$disk}' disk. ".$e->getMessage());
            }

            if ($path === false) {
                Log::error('Draft media upload returned no path', ['disk' => $disk, 'user_id' => Auth::id()]);

                return $this->failure("Upload failed: the '{$disk}' disk rejected the file without an error.");
            }

            /** @var WorkItemMedia $media */
            $media = WorkItemMedia::create([
                'project_id' => null,
                'uploaded_by' => Auth::id(),
                // Recorded, not assumed — show() streams from whatever this says.
                'disk' => $disk,
                'path' => $path,
                'name' => $file->getClientOriginalName(),
                'mime' => $file->getMimeType() ?: 'application/octet-stream',
                'size' => $file->getSize() ?: 0,
            ]);

            $result[] = [
                'url' => $media->url(),
                'name' => $media->name,
                'size' => $media->size,
            ];
        }

        return response()->json(['result' => $result]);
    }

    /**
     * GET /drafts/media — the editor's image gallery.
     *
     * The signed-in user's own draft uploads only. Not "this workspace's": a draft is private,
     * and a gallery that showed everyone's would be a window into other people's unpublished
     * work through a picker.
     */
    public function index(): JsonResponse
    {
        abort_unless(Auth::user()->can('createDraft', WorkItem::class), 404);

        $result = WorkItemMedia::query()
            ->whereNull('project_id')
            ->where('uploaded_by', Auth::id())
            ->latest('id')
            ->limit((int) config('projects.media.gallery_size'))
            ->get()
            ->map(fn (WorkItemMedia $m) => [
                'src' => $m->url(),
                'name' => $m->name,
                'alt' => $m->name,
            ])
            ->all();

        return response()->json(['result' => $result]);
    }

    /**
     * GET /drafts/media/{media} — stream one image.
     *
     * This route outlives the draft. The URL is written into the description HTML when the
     * image is inserted, and publishing does not rewrite that markup — so the same URL has to
     * serve two different audiences over its life, and answers to whichever applies:
     *
     *  - the uploader, always: it is their image, drafted or published;
     *  - once the row has been re-homed to a project, anyone who can see that project's work
     *    items, because by then the image is part of a work item other people are reading.
     *
     * 404 rather than 403 throughout, so a refusal never confirms that a file exists.
     */
    public function show(WorkItemMedia $media): StreamedResponse
    {
        abort_unless($this->mayRead($media), 404);

        $disk = Storage::disk($media->disk);
        abort_unless($disk->exists($media->path), 404);

        return $disk->response($media->path, $media->name, [
            'Content-Type' => $media->mime,
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    private function mayRead(WorkItemMedia $media): bool
    {
        $user = Auth::user();

        if ((int) $media->uploaded_by === (int) $user->id) {
            return true;
        }

        // Still project-less: nobody but the uploader has any business reading it.
        if ($media->isDraftMedia()) {
            return false;
        }

        $project = Project::find($media->project_id);

        return $project !== null && $user->can('viewAny', [WorkItem::class, $project]);
    }

    /** The editor shows `errorMessage` to the author; anything else looks like silence. */
    private function failure(string $message, int $status = 422): JsonResponse
    {
        return response()->json(['errorMessage' => $message], $status);
    }
}
