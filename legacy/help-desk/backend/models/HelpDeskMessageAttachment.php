<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * A file that arrived with an email (Inbound Email requirements §11.6). TENANT-SCOPED.
 *
 * A row can exist WITHOUT a stored file: an attachment over the configured size limit is
 * recorded with `skipped_reason` and no path. That is deliberate — an agent needs to know the
 * customer sent something, and a message that quietly loses a 40 MB video reads as a customer
 * who sent nothing, which is how "I already sent you the screenshot" becomes an argument.
 */
class HelpDeskMessageAttachment extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'help_desk_id',
        'help_desk_message_id',
        'disk',
        'path',
        'name',
        'mime',
        'size',
        'content_id',
        'skipped_reason',
    ];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(HelpDeskMessage::class, 'help_desk_message_id');
    }

    /** Is the file actually here, or only the record of it? */
    public function isStored(): bool
    {
        return $this->path !== null;
    }

    /** Human-readable size, for a list that has to fit it in a column. */
    public function readableSize(): string
    {
        $bytes = (int) $this->size;

        if ($bytes < 1024) {
            return $bytes.' B';
        }

        $units = ['KB', 'MB', 'GB'];
        $value = $bytes / 1024;
        $unit = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return round($value, $value < 10 ? 1 : 0).' '.$units[$unit];
    }
}
