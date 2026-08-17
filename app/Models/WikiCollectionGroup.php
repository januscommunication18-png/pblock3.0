<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * A section inside a collection (docs/features/wiki.md). TENANT-SCOPED.
 */
class WikiCollectionGroup extends Model
{
    use BelongsToTenant;

    protected $table = 'wiki_collection_groups';

    protected $fillable = [
        'tenant_id',
        'wiki_collection_id',
        'parent_id',
        'name',
        'label',
        'short_description',
        'long_description',
        'position',
        'created_by',
    ];

    protected function casts(): array
    {
        return ['position' => 'integer', 'parent_id' => 'integer'];
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(WikiCollection::class, 'wiki_collection_id');
    }

    public function pages(): HasMany
    {
        return $this->hasMany(WikiPage::class, 'wiki_group_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position');
    }

    public function isSubGroup(): bool
    {
        return $this->parent_id !== null;
    }

    /** @return array<string, mixed> */
    public function toCard(): array
    {
        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            'name' => $this->name,
            'label' => $this->label,
            'short_description' => $this->short_description,
            'long_description' => $this->long_description,
            'position' => $this->position,
        ];
    }
}
