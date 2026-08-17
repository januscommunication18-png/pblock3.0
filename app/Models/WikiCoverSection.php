<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * One card on a collection's Cover Page (docs/features/wiki-cover-page.md). TENANT-SCOPED.
 */
class WikiCoverSection extends Model
{
    use BelongsToTenant;

    public const VISUAL_ICON = 'icon';

    public const VISUAL_NONE = 'none';

    public const TO_GROUP = 'group';

    public const TO_PAGE = 'page';

    protected $attributes = [
        'visual_type' => self::VISUAL_NONE,
    ];

    protected $fillable = [
        'tenant_id',
        'wiki_cover_id',
        'visual_type',
        'icon_key',
        'title',
        'description',
        'destination_type',
        'destination_id',
        'position',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return ['position' => 'integer', 'destination_id' => 'integer'];
    }

    public function cover(): BelongsTo
    {
        return $this->belongsTo(WikiCover::class, 'wiki_cover_id');
    }

    /** @return array<int, string> */
    public static function visualTypes(): array
    {
        return [self::VISUAL_ICON, self::VISUAL_NONE];
    }

    /** @return array<int, string> */
    public static function destinationTypes(): array
    {
        return [self::TO_GROUP, self::TO_PAGE];
    }

    /** @return array<string, mixed> */
    public function toCard(): array
    {
        return [
            'id' => $this->id,
            'visual_type' => $this->visual_type,
            // Only the chosen visual is sent. An icon left behind by somebody who switched to
            // None would come back the moment they switched away and back again.
            'icon_key' => $this->visual_type === self::VISUAL_ICON ? $this->icon_key : null,
            'title' => $this->title,
            'description' => $this->description,
            'destination_type' => $this->destination_type,
            'destination_id' => $this->destination_id,
            'position' => $this->position,
        ];
    }
}
