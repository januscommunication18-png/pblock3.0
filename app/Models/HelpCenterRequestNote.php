<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * One internal note on a Request (docs/features/help-center.md, P42). TENANT-SCOPED.
 *
 * Private to the team by construction — see the migration for why the guarantee lives in the
 * table's shape rather than in a flag somebody has to remember to check.
 */
class HelpCenterRequestNote extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'help_center_request_id',
        'author_id',
        'content',
        'mentions',
        'edited_at',
    ];

    protected function casts(): array
    {
        return ['mentions' => 'array', 'edited_at' => 'datetime'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * @param  Collection<int, User>|null  $people  mentioned users, resolved once by the caller
     * @return array<string, mixed>
     */
    public function toPayload(?Collection $people = null): array
    {
        $ids = array_map('intval', (array) $this->mentions);

        return [
            'id' => $this->id,
            'kind' => 'note',
            'label' => 'Internal Note',
            'content' => (string) $this->content,
            'author' => $this->author ? [
                'id' => $this->author->id,
                'name' => $this->author->displayName(),
                'initial' => mb_strtoupper(mb_substr($this->author->displayName(), 0, 1)),
                'avatar_url' => $this->author->avatar_url ?? null,
            ] : null,
            'author_name' => $this->author?->displayName() ?? 'Someone',
            /*
             * The people named, resolved for display.
             *
             * Rendered from the stored ids rather than read out of the markup: the ids are what
             * was validated on the way in, and the HTML is presentation — the same rule the
             * `mentions` table follows for work items.
             */
            'mentions' => $people === null
                ? []
                : $people->whereIn('id', $ids)->map(fn (User $u) => [
                    'id' => $u->id,
                    'name' => $u->displayName(),
                ])->values()->all(),
            'edited' => $this->edited_at !== null,
            'at' => $this->created_at?->format('M j, Y \a\t g:i A'),
            'ago' => $this->created_at?->diffForHumans(),
            'sort' => $this->created_at?->getTimestamp() ?? 0,
            'mine' => $this->author_id !== null && (int) $this->author_id === (int) auth()->id(),
        ];
    }
}
