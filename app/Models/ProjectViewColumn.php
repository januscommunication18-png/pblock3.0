<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * One column mapped into a View (Views §8, §10, §22.2).
 *
 * Holds only what the USER chose: which field, where it sits, how wide, what to call it. What
 * the field *is* — its label, type, whether it can be edited at all, which project feature it
 * needs — lives in ViewFieldCatalog, so a field's definition is in one place and every View
 * that maps it follows a change automatically.
 */
class ProjectViewColumn extends Model
{
    use BelongsToTenant;

    protected $table = 'project_view_columns';

    /** §8.1 — pinned left, always visible while the grid scrolls horizontally. */
    public const POSITION_FIXED = 'fixed';

    /** §8.2 — scrolls horizontally past the fixed columns. */
    public const POSITION_SCROLL = 'scroll';

    protected $fillable = [
        'tenant_id',
        'project_view_id',
        'source_type',
        'source_field',
        'display_name',
        'position_type',
        'sort_order',
        'width',
        'is_visible',
        'is_editable',
        'formatting_json',
    ];

    protected function casts(): array
    {
        return [
            'is_visible' => 'boolean',
            'is_editable' => 'boolean',
            'formatting_json' => 'array',
            'sort_order' => 'integer',
            'width' => 'integer',
        ];
    }

    public function view(): BelongsTo
    {
        return $this->belongsTo(ProjectView::class, 'project_view_id');
    }

    /** `epic.name` — the stable key the client and the catalog both address a column by. */
    public function key(): string
    {
        return $this->source_type.'.'.$this->source_field;
    }

    public function isFixed(): bool
    {
        return $this->position_type === self::POSITION_FIXED;
    }
}
