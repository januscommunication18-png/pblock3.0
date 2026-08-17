<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * One page inside a collection (docs/features/wiki.md). TENANT-SCOPED.
 */
class WikiPage extends Model
{
    use BelongsToTenant, SoftDeletes;

    /** A linked page stands for a project page (docs/features/wiki-linked-pages.md). */
    public const SOURCE_PROJECT_PAGE = 'project_page';

    protected $fillable = [
        'tenant_id',
        'wiki_collection_id',
        'title',
        'content',
        'source_type',
        'source_page_id',
        'parent_id',
        'wiki_group_id',
        'position',
        'archived_at',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(WikiCollection::class, 'wiki_collection_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(WikiCollectionGroup::class, 'wiki_group_id');
    }

    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(WikiLabel::class, 'wiki_page_label')->withTimestamps();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    // ---- linked pages (docs/features/wiki-linked-pages.md) --------------------------------

    /** Does this row stand for a page that lives somewhere else? */
    public function isLinked(): bool
    {
        return $this->source_type !== null;
    }

    /** The project page a linked row points at. Null on an ordinary page. */
    public function sourcePage(): BelongsTo
    {
        return $this->belongsTo(ProjectPage::class, 'source_page_id');
    }

    /**
     * Eager-load what the accessors read.
     *
     * NOT optional on any query that lists pages. `title` resolves through the source on a linked
     * row, so without this a collection's page list is one extra query per link — and the lists
     * that render titles are exactly the ones that render many rows at once.
     */
    public function scopeWithSource(Builder $query): Builder
    {
        return $query->with(['sourcePage' => fn ($q) => $q->withTrashed()]);
    }

    /**
     * The title, resolved.
     *
     * A linked row stores none of its own: storing it would be the copy this feature avoids, and
     * it would disagree with the source the first time somebody renamed the project page.
     */
    public function getTitleAttribute(?string $stored): string
    {
        if (! $this->isLinked()) {
            return (string) $stored;
        }

        /*
         * A source that has been deleted leaves a row that must still render — a page nobody can
         * open is better than a screen that will not draw.
         *
         * The relation is loaded `withTrashed()`, so a soft-deleted page is still THERE; going
         * on to print its title would keep showing content somebody deleted, which is the one
         * outcome deleting it was meant to prevent.
         */
        return $this->sourceIsMissing() ? 'Unavailable page' : $this->sourcePage->title;
    }

    /** The body, resolved the same way — and absent once the source is gone. */
    public function getContentAttribute(?string $stored): ?string
    {
        if (! $this->isLinked()) {
            return $stored;
        }

        return $this->sourceIsMissing() ? null : $this->sourcePage->content;
    }

    /** Whether the thing a linked row points at is still there. */
    public function sourceIsMissing(): bool
    {
        return $this->isLinked() && ($this->sourcePage === null || $this->sourcePage->trashed());
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * The row the collection's page table draws (docs/features/wiki.md).
     *
     * `nested` is a COUNT, not the children themselves: the column answers "does this group
     * anything?", and loading a tree to print a number is a query per row for nothing.
     *
     * @return array<string, mixed>
     */
    public function toCard(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'parent_id' => $this->parent_id,
            'group_id' => $this->wiki_group_id,
            'parent_title' => $this->parent?->title,
            'position' => $this->position,
            'archived' => $this->isArchived(),
            // The table draws a linked row differently: it is somebody else's page, shown here.
            'linked' => $this->isLinked(),
            'source_missing' => $this->sourceIsMissing(),
            'source_url' => $this->isLinked() && $this->sourcePage && ! $this->sourcePage->trashed()
                ? route('projects.pages.show', [
                    'project' => $this->sourcePage->project_id, 'page' => $this->sourcePage->id,
                ])
                : null,
            'nested' => (int) ($this->children_count ?? $this->children()->count()),
            'labels' => $this->relationLoaded('labels')
                ? $this->labels->map(fn (WikiLabel $l) => [
                    'id' => $l->id, 'name' => $l->name, 'color' => $l->color,
                ])->values()->all()
                : [],
            'owner' => $this->creator ? [
                'id' => $this->creator->id,
                'name' => $this->creator->full_name ?: $this->creator->email,
                'avatar_url' => $this->creator->avatar_url,
                'initial' => mb_strtoupper(mb_substr($this->creator->full_name ?: $this->creator->email, 0, 1)),
            ] : null,
            'updated_at' => $this->updated_at?->toIso8601String(),
            'last_activity' => $this->updated_at?->diffForHumans(),
            'updated_by' => $this->editor?->full_name ?: $this->editor?->email,
        ];
    }
}
