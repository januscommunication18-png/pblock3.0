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

    protected $fillable = [
        'tenant_id',
        'wiki_collection_id',
        'title',
        'content',
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
