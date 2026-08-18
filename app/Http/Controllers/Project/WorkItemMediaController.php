<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
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
 * Images for the work item description editor: upload, gallery, and serving.
 *
 * The request and response shapes are the editor's, not ours: each file arrives as
 * `file-N` and the answer is `{"result": [{url, name, size}]}`, or `{"errorMessage": "…"}`
 * on failure. They were SunEditor's contract originally and the Quill image handler was
 * written to the same shape, so the endpoint did not have to move when the editor did.
 *
 * Files are stored on the PRIVATE disk and streamed through `show()` behind the same
 * `view` ability that gates the project. Putting them on the public disk would hand out a
 * URL that works for anyone who receives it, regardless of workspace (CLAUDE.md §7).
 */
class WorkItemMediaController extends Controller
{
    /** POST /projects/{project}/work-items/media — the editor's image upload target. */
    public function store(Request $request, Project $project): JsonResponse
    {
        // Uploading is a write: whoever may create work items may attach images to them.
        if (! Auth::user()->can('create', [WorkItem::class, $project])) {
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

        foreach ($files as $file) {
            $validator = Validator::make(['file' => $file], $rules);
            if ($validator->fails()) {
                // One bad file fails the batch: a half-inserted set of images would leave the
                // author guessing which one did not make it.
                return $this->failure($validator->errors()->first());
            }

            $disk = (string) config('filesystems.media_disk', 'local');

            // 'private' is explicit: the `spaces` disk defaults writes to public-read, and
            // inheriting that would publish project attachments to an unauthenticated URL,
            // bypassing the `view` ability that show() enforces.
            try {
                $path = $file->store(
                    "work-item-media/{$project->tenant_id}/{$project->id}",
                    ['disk' => $disk, 'visibility' => 'private'],
                );
            } catch (\Throwable $e) {
                Log::error('Work item media upload failed', [
                    'disk' => $disk,
                    'bucket' => config("filesystems.disks.{$disk}.bucket"),
                    'project_id' => $project->id,
                    'file' => $file->getClientOriginalName(),
                    'error' => $e->getMessage(),
                ]);

                return $this->failure("Upload failed: could not write to the '{$disk}' disk. ".$e->getMessage());
            }

            if ($path === false) {
                Log::error('Work item media upload returned no path', ['disk' => $disk, 'project_id' => $project->id]);

                return $this->failure("Upload failed: the '{$disk}' disk rejected the file without an error.");
            }

            /** @var WorkItemMedia $media */
            $media = WorkItemMedia::create([
                'project_id' => $project->id,
                'uploaded_by' => Auth::id(),
                // The disk is RECORDED, not assumed: show() streams from whatever this says,
                // so rows written before a disk switch keep resolving to their old home.
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
     * GET /projects/{project}/work-items/media — the editor's image gallery.
     * Scoped to this project, so the picker can never show another project's uploads.
     */
    public function index(Project $project): JsonResponse
    {
        abort_unless(Auth::user()->can('viewAny', [WorkItem::class, $project]), 404);

        $result = WorkItemMedia::query()
            ->where('project_id', $project->id)
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
     * GET /projects/{project}/work-items/media/{media} — stream one image.
     *
     * 404 rather than 403 on a foreign project's media, so the response cannot confirm that
     * a file exists somewhere the caller cannot reach (§12).
     */
    public function show(Project $project, WorkItemMedia $media): StreamedResponse
    {
        abort_unless($media->project_id === $project->id, 404);
        abort_unless(Auth::user()->can('viewAny', [WorkItem::class, $project]), 404);

        $disk = Storage::disk($media->disk);
        abort_unless($disk->exists($media->path), 404);

        return $disk->response($media->path, $media->name, [
            'Content-Type' => $media->mime,
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /** The editor shows `errorMessage` to the author; anything else looks like silence. */
    private function failure(string $message, int $status = 422): JsonResponse
    {
        return response()->json(['errorMessage' => $message], $status);
    }
}
