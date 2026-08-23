<?php

namespace App\Services\HelpCenter\Inbound;

use App\Models\HelpCenterMessage;
use App\Models\HelpCenterMessageAttachment;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Putting a customer's attachments on their ticket (docs/features/help-center.md, P66).
 *
 * The gap open since P62: `PostmarkPayload` never read `Attachments`, so a ticket that said
 * "invoice attached" arrived with no invoice.
 *
 * ## Everything is kept except what is only ever dangerous
 *
 * Work item attachments use an ALLOW-list, and rightly: a colleague told "that type is not
 * accepted" picks another. A customer emailing support cannot be told anything — they have
 * already sent it — so a file we quietly refuse is a file that has simply vanished, and the
 * agent reads "see attached" with nothing attached. That is the failure this exists to fix, so
 * refusing must be rare and must never be silent: whatever is dropped is COUNTED on the message
 * with a reason, and the ticket says so.
 *
 * ## The filename is the sender's, and is treated as such
 *
 * It is displayed and used to name the download, and it is NEVER used to build the storage path.
 * `Str::random()` names the file on disk, so a sender who calls their attachment
 * `../../../.env` gets a row whose `name` reads exactly that and a file that landed where every
 * other file lands.
 */
class InboundAttachmentStore
{
    /**
     * Store every attachment on one message. Never throws.
     *
     * A file that will not write must not lose the MESSAGE — the customer's words are the thing
     * worth keeping, and an ingest that failed on a corrupt PDF would be retried by Postmark
     * and fail again on the same PDF forever. So failures are logged, counted, and the ingest
     * carries on.
     */
    public function store(HelpCenterMessage $message, PostmarkPayload $payload): void
    {
        $files = $payload->attachments();

        if ($files === []) {
            return;
        }

        $disk = (string) config('filesystems.media_disk', 'local');
        $maxBytes = (int) config('help-center.attachments.max_kb', 25600) * 1024;
        $maxCount = (int) config('help-center.attachments.max_per_message', 25);
        $blocked = array_map('strtolower', (array) config('help-center.attachments.blocked_extensions', []));

        $skipped = [];
        $kept = 0;

        foreach ($files as $file) {
            $name = $this->safeName($file['name']);

            if ($kept >= $maxCount) {
                $skipped[] = ['name' => $name, 'reason' => 'Too many attachments on one email'];

                continue;
            }

            if (in_array($this->extension($name), $blocked, true)) {
                $skipped[] = ['name' => $name, 'reason' => 'Blocked file type'];

                continue;
            }

            $size = strlen($file['content']);

            if ($size > $maxBytes) {
                $skipped[] = ['name' => $name, 'reason' => 'Larger than '.round($maxBytes / 1048576).' MB'];

                continue;
            }

            /*
             * A random basename, and the sender's extension kept for it.
             *
             * The extension is what lets somebody who downloads the file open it in the right
             * application; the basename is ours because the sender's is untrusted input in a
             * path. The original name survives in full on the row.
             */
            $ext = $this->extension($name);
            $path = 'help-center-attachments/'
                .$message->tenant_id.'/'
                .$message->help_center_request_id.'/'
                .$message->id.'/'
                .Str::random(40).($ext === '' ? '' : '.'.$ext);

            try {
                // 'private' is explicit: the `spaces` disk defaults writes to public-read, and
                // inheriting that would publish a customer's invoice to an unauthenticated URL,
                // bypassing the check the download route enforces.
                Storage::disk($disk)->put($path, $file['content'], ['visibility' => 'private']);
            } catch (Throwable $e) {
                Log::error('help-center.attachment.store_failed', [
                    'message_id' => $message->id,
                    'disk' => $disk,
                    'name' => $name,
                    'error' => $e->getMessage(),
                ]);

                $skipped[] = ['name' => $name, 'reason' => 'Could not be saved'];

                continue;
            }

            HelpCenterMessageAttachment::create([
                'tenant_id' => $message->tenant_id,
                'help_center_request_id' => $message->help_center_request_id,
                'help_center_message_id' => $message->id,
                'disk' => $disk,
                'path' => $path,
                'name' => $name,
                'mime' => mb_substr($file['mime'], 0, 191),
                'size' => $size,
                'content_id' => $file['content_id'],
                'is_inline' => $file['content_id'] !== null,
            ]);

            $kept++;
        }

        if ($skipped !== []) {
            $message->forceFill([
                'attachments_skipped' => count($skipped),
                'attachments_skipped_detail' => $skipped,
            ])->save();

            Log::warning('help-center.attachment.skipped', [
                'message_id' => $message->id,
                'skipped' => $skipped,
            ]);
        }
    }

    /**
     * The sender's filename, made safe to DISPLAY and to hand to a download header.
     *
     * Path separators and control characters go — a name containing a newline can forge a
     * second header on the download response, and one containing a slash reads as a directory
     * in every list that shows it. What is left is still the sender's name, including its
     * spaces and its accents.
     */
    private function safeName(string $name): string
    {
        $name = str_replace(['/', '\\', "\0"], '-', trim($name));
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
        $name = trim($name, '. ');

        return $name === '' ? 'attachment' : mb_substr($name, 0, 200);
    }

    private function extension(string $name): string
    {
        $ext = mb_strtolower((string) pathinfo($name, PATHINFO_EXTENSION));

        // Only a real extension — a "name" of `2026.08.22 invoice` has no type, and treating
        // `22 invoice` as one would both mislabel the file and defeat the blocked-type check.
        return preg_match('/^[a-z0-9]{1,12}$/', $ext) === 1 ? $ext : '';
    }
}
