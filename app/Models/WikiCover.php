<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * A collection's Cover Page (docs/features/wiki-cover-page.md). TENANT-SCOPED.
 *
 * Presentation only. Which cards a reader is shown is decided by the collection's own access
 * rules, never by anything stored here.
 */
class WikiCover extends Model
{
    use BelongsToTenant;

    public const ALIGN_LEFT = 'left';

    public const ALIGN_CENTER = 'center';

    public const ALIGN_RIGHT = 'right';

    public const LAYOUT_AUTO = 'auto';

    /** `two`, not `2_column`: a value that cannot be a constant name reads badly at every call site. */
    public const LAYOUT_TWO = 'two';

    public const LAYOUT_THREE = 'three';

    /**
     * Defaults the MODEL knows, not only the column.
     *
     * A collection with no cover row is described by an unsaved instance of this class, and it
     * has to answer the same questions a saved one does — the screen reads `toCard()` either
     * way. `WikiCollection` states its defaults here for the same reason.
     */
    protected $attributes = [
        'is_enabled' => false,
        'global_search_enabled' => true,
        'previous_next_enabled' => true,
        'on_this_page_enabled' => true,
        'content_alignment' => self::ALIGN_CENTER,
        'card_layout' => self::LAYOUT_AUTO,
    ];

    protected $fillable = [
        'tenant_id',
        'wiki_collection_id',
        'is_enabled',
        'title',
        'short_description',
        'global_search_enabled',
        'previous_next_enabled',
        'on_this_page_enabled',
        'content_alignment',
        'card_layout',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'global_search_enabled' => 'boolean',
            'previous_next_enabled' => 'boolean',
            'on_this_page_enabled' => 'boolean',
        ];
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(WikiCollection::class, 'wiki_collection_id');
    }

    /**
     * The collection's cover — the saved one, or the defaults it would start from.
     *
     * Unsaved rather than created on read: opening a collection is not a decision to give it a
     * cover, and a table with a row for every collection nobody has configured says the opposite.
     */
    public static function forCollection(WikiCollection $collection): self
    {
        return static::query()->where('wiki_collection_id', $collection->id)->first()
            ?? new self([
                'tenant_id' => $collection->tenant_id,
                'wiki_collection_id' => $collection->id,
            ]);
    }

    /** @return array<int, string> */
    public static function alignments(): array
    {
        return [self::ALIGN_LEFT, self::ALIGN_CENTER, self::ALIGN_RIGHT];
    }

    /** @return array<int, string> */
    public static function layouts(): array
    {
        return [self::LAYOUT_AUTO, self::LAYOUT_TWO, self::LAYOUT_THREE];
    }

    /** @return array<string, mixed> */
    public function toCard(): array
    {
        return [
            'is_enabled' => (bool) $this->is_enabled,
            'title' => $this->title,
            'short_description' => $this->short_description,
            'global_search_enabled' => (bool) $this->global_search_enabled,
            'previous_next_enabled' => (bool) $this->previous_next_enabled,
            'on_this_page_enabled' => (bool) $this->on_this_page_enabled,
            'content_alignment' => $this->content_alignment,
            'card_layout' => $this->card_layout,
        ];
    }
}
