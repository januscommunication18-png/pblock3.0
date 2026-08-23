<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * An INTERNAL update on a Request (docs/features/help-center.md, P36). TENANT-SCOPED.
 *
 * The work item update's vocabulary and behaviour, on a Request. Internal by construction: it
 * has no recipient and no delivery state, so there is nothing here that could be emailed even by
 * accident — replying to the customer writes a message instead.
 */
class HelpCenterRequestUpdate extends Model
{
    use BelongsToTenant, SoftDeletes;

    /** The work item's three words, unchanged, so an agent reading both reads one list. */
    public const STATUSES = ['on_track', 'at_risk', 'off_track'];

    protected $fillable = [
        'tenant_id',
        'help_center_request_id',
        'author_id',
        'status',
        'content',
        'edited_at',
    ];

    protected function casts(): array
    {
        return ['edited_at' => 'datetime'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** How the three statuses read and colour. Config would be overkill for a closed set of three. */
    public static function statusMeta(string $status): array
    {
        return [
            'on_track' => ['label' => 'On track', 'color' => '#22c55e'],
            'at_risk' => ['label' => 'At risk', 'color' => '#f59e0b'],
            'off_track' => ['label' => 'Off track', 'color' => '#ef4444'],
        ][$status] ?? ['label' => ucfirst($status), 'color' => '#6b7280'];
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        $meta = self::statusMeta((string) $this->status);

        return [
            'id' => $this->id,
            'kind' => 'update',
            'status' => $this->status,
            'status_label' => $meta['label'],
            'status_color' => $meta['color'],
            'content' => (string) $this->content,
            'author' => $this->author ? [
                'id' => $this->author->id,
                'name' => $this->author->displayName(),
                'initial' => mb_strtoupper(mb_substr($this->author->displayName(), 0, 1)),
                'avatar_url' => $this->author->avatar_url ?? null,
            ] : null,
            'author_name' => $this->author?->displayName() ?? 'Someone',
            'edited' => $this->edited_at !== null,
            'at' => $this->created_at?->format('M j, Y \a\t g:i A'),
            'ago' => $this->created_at?->diffForHumans(),
            'sort' => $this->created_at?->getTimestamp() ?? 0,
            'mine' => $this->author_id !== null && (int) $this->author_id === (int) auth()->id(),
        ];
    }
}
