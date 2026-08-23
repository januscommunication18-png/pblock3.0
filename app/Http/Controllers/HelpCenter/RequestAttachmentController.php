<?php

namespace App\Http\Controllers\HelpCenter;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HelpCenter\Concerns\GuardsHelpCenter;
use App\Models\HelpCenterMessageAttachment;
use App\Models\HelpCenterRequest;
use App\Models\HelpCenterSpace;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloading a file a customer emailed in (docs/features/help-center.md, P66).
 *
 * Its own controller for the same reason `RequestNoteController` is: what it must never do is
 * as important as what it does. There is no upload here, no delete, and no way to reach a file
 * except through the Request that owns it — a customer's invoice is stored on a PRIVATE disk
 * precisely so that holding the link is not the same as being allowed to read it.
 *
 * Gated on VIEWING the Space, not on updating it: an attachment is part of what the ticket says,
 * so anyone who can read the ticket can read its files. That is the rule the work item's
 * attachments follow, with a Space where a project would be.
 *
 * Refusals are 404 rather than 403 throughout, so a response never confirms that a file exists
 * somewhere the caller cannot reach.
 */
class RequestAttachmentController extends Controller
{
    use GuardsHelpCenter;

    /** GET /help-center/spaces/{space}/requests/{request}/attachments/{attachment} */
    public function show(
        HelpCenterSpace $space,
        int $request,
        HelpCenterMessageAttachment $attachment,
    ): StreamedResponse {
        $this->helpCenterWorkspace();
        abort_unless(Auth::user()->can('view', $space), 404);

        // The file must belong to THIS Request, and the Request to THIS Space. Two checks, not
        // one: without the first, any attachment id in the workspace would serve through any
        // ticket the caller happens to be able to open.
        $model = HelpCenterRequest::query()->forSpace($space->id)->findOrFail($request);

        abort_unless((int) $attachment->help_center_request_id === (int) $model->id, 404);

        $disk = Storage::disk($attachment->disk);

        if (! $disk->exists($attachment->path)) {
            // A row pointing at a file the disk does not have is a storage problem, not a
            // missing attachment. It renders as a silent 404, so say so in the log.
            Log::warning('help-center.attachment.missing_from_disk', [
                'attachment_id' => $attachment->id,
                'disk' => $attachment->disk,
                'path' => $attachment->path,
            ]);

            abort(404);
        }

        /*
         * ALWAYS a download, never inline.
         *
         * `download()` sets `Content-Disposition: attachment`, and that is the whole point: this
         * is a file a STRANGER sent us. Serving a sender's `text/html` or `.svg` inline would
         * run their markup in this application's origin, against a session that is already
         * signed in. Nothing on a ticket needs to render in place badly enough to be worth that.
         *
         * `X-Content-Type-Options: nosniff` stops a browser second-guessing the type and
         * rendering it anyway.
         */
        return $disk->download($attachment->path, $attachment->name, [
            'Content-Type' => $attachment->mime ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
