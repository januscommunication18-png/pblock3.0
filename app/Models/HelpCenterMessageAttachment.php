<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * A file that arrived with a customer's email (docs/features/help-center.md, P66).
 *
 * TENANT-SCOPED (CLAUDE.md §7): `BelongsToTenant` confines every query to the active workspace
 * and stamps `tenant_id` on insert. The Space policy is applied on top, because authorization
 * for reading the file resolves through the Space, not the workspace.
 */
class HelpCenterMessageAttachment extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'help_center_request_id',
        'help_center_message_id',
        'disk',
        'path',
        'name',
        'mime',
        'size',
        'content_id',
        'is_inline',
    ];

    protected function casts(): array
    {
        return ['size' => 'integer', 'is_inline' => 'boolean'];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(HelpCenterMessage::class, 'help_center_message_id');
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(HelpCenterRequest::class, 'help_center_request_id');
    }

    /**
     * What the ticket renders for this file.
     *
     * `url` is the AUTHORIZED download route, never a path on the disk: the files are stored
     * private precisely so that having the link is not the same as being allowed to read it.
     *
     * @return array<string, mixed>
     */
    public function toPayload(int $spaceId): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'size' => $this->size,
            'size_label' => self::humanSize((int) $this->size),
            'mime' => $this->mime,
            'inline' => (bool) $this->is_inline,
            'url' => route('help-center.spaces.requests.attachments.show', [
                'space' => $spaceId,
                'request' => $this->help_center_request_id,
                'attachment' => $this->id,
            ]),
        ];
    }

    /**
     * "412 KB" — formatted on the SERVER because it is displayed and never computed with.
     *
     * The same reasoning the ticket's dates follow (P33): a size formatted in the browser is how
     * two screens end up disagreeing about what a kilobyte is.
     */
    public static function humanSize(int $bytes): string
    {
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

        return round($value, $value >= 10 ? 0 : 1).' '.$units[$unit];
    }
}
