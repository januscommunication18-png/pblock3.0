<?php

namespace App\Services\HelpDesk;

use App\Models\HelpDeskMessage;
use App\Models\HelpDeskMessageAttachment;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Keeps the files that arrived with an email (Inbound Email requirements §11.6, §12).
 *
 * The thing to hold on to: NOBODY HERE CHOSE THESE FILES. A work-item attachment was uploaded by
 * somebody with an account; these were sent by a stranger who knows an email address. Every rule
 * below follows from that.
 *
 *   - a per-file and a per-message ceiling, from config. An inbound endpoint with no ceiling is
 *     a disk-filling service anybody with the address can use;
 *   - a file over the ceiling is RECORDED WITHOUT ITS CONTENTS rather than dropped. An agent
 *     needs to know the customer sent something, because a message that quietly loses a 40 MB
 *     video reads as a customer who sent nothing — which is how "I already sent you the
 *     screenshot" becomes an argument;
 *   - the stored path is random and the extension comes from the STORED name, never from what
 *     the sender called the file. The original name is kept for the download and nothing else;
 *   - written private, because the disk may default to public-read, and an attachment on a
 *     public URL is a customer's private file published by a mail server.
 *
 * Nothing here throws. A message whose attachments could not be written is still a message the
 * customer sent, and losing the whole conversation over one unwritable file would be the worse
 * failure — so a failure is logged, recorded on the row, and the message survives it.
 */
class InboundAttachmentStore
{
    /**
     * @param  array<int, array<string, mixed>>  $attachments  as normalized by the provider mapper
     */
    public function store(HelpDeskMessage $message, array $attachments): void
    {
        if ($attachments === []) {
            return;
        }

        $disk = (string) config('filesystems.media_disk', 'local');
        $maxFile = (int) config('help-desk.attachments.max_file_bytes');
        $remaining = (int) config('help-desk.attachments.max_total_bytes');

        foreach ($attachments as $attachment) {
            if (! is_array($attachment)) {
                continue;
            }

            $name = $this->name($attachment);
            $binary = $this->decode($attachment);
            $size = $binary === null ? (int) ($attachment['size'] ?? 0) : strlen($binary);

            $skipped = match (true) {
                $binary === null => 'The provider sent no readable content.',
                $size > $maxFile => 'Too large to keep — over '.$this->readable($maxFile).'.',
                $size > $remaining => 'Skipped — this message was over the total attachment limit.',
                default => null,
            };

            $path = $skipped === null
                ? $this->write($disk, $message, $name, (string) $binary)
                : null;

            if ($skipped === null && $path === null) {
                $skipped = 'Could not be stored.';
            }

            if ($path !== null) {
                $remaining -= $size;
            }

            HelpDeskMessageAttachment::create([
                'tenant_id' => $message->tenant_id,
                'help_desk_id' => $message->help_desk_id,
                'help_desk_message_id' => $message->id,
                'disk' => $disk,
                'path' => $path,
                'name' => $name,
                'mime' => $this->mime($attachment),
                'size' => $size,
                'content_id' => $this->contentId($attachment),
                'skipped_reason' => $skipped,
            ]);
        }
    }

    /** The stored path, or null when the write failed. */
    private function write(string $disk, HelpDeskMessage $message, string $name, string $binary): ?string
    {
        $extension = Str::lower((string) pathinfo($name, PATHINFO_EXTENSION));
        $extension = preg_match('/^[a-z0-9]{1,10}$/', $extension) ? '.'.$extension : '';

        // Random, not derived from anything: §13 asks for non-guessable storage references, and
        // a path built from a message id is a path somebody can walk.
        $path = sprintf(
            'help-desk-attachments/%s/%d/%s%s',
            $message->tenant_id,
            $message->help_desk_id,
            Str::random(40),
            $extension,
        );

        try {
            $written = Storage::disk($disk)->put($path, $binary, ['visibility' => 'private']);
        } catch (\Throwable $e) {
            Log::error('help_desk.inbound.attachment_failed', [
                'disk' => $disk,
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return $written ? $path : null;
    }

    /** @param array<string, mixed> $attachment */
    private function decode(array $attachment): ?string
    {
        $content = (string) ($attachment['content'] ?? '');

        if ($content === '') {
            return null;
        }

        // Strict: silently ignoring invalid characters would store a corrupt file that looks
        // fine in the list and fails when somebody downloads it.
        $binary = base64_decode($content, true);

        return $binary === false ? null : $binary;
    }

    /** @param array<string, mixed> $attachment */
    private function name(array $attachment): string
    {
        // The sender chose this string. It is kept for the download and never used to build a
        // path, so a name of "../../.env" is a strange filename rather than a traversal.
        $name = trim((string) ($attachment['name'] ?? ''));

        return Str::limit($name !== '' ? $name : 'attachment', 200, '');
    }

    /** @param array<string, mixed> $attachment */
    private function mime(array $attachment): ?string
    {
        $mime = trim((string) ($attachment['content_type'] ?? ''));

        // Declared by the sender, so it is a hint and stored as one — never the basis of a
        // decision about what the file is.
        return $mime !== '' ? Str::limit($mime, 128, '') : null;
    }

    /** @param array<string, mixed> $attachment */
    private function contentId(array $attachment): ?string
    {
        $id = trim((string) ($attachment['content_id'] ?? ''), " \t\n\r\0\x0B<>");

        return $id !== '' ? Str::limit($id, 190, '') : null;
    }

    private function readable(int $bytes): string
    {
        return round($bytes / (1024 * 1024), 1).' MB';
    }
}
