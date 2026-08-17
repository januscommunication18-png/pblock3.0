<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * One person's access to one collection (docs/features/wiki.md). TENANT-SCOPED.
 */
class WikiCollectionMember extends Model
{
    use BelongsToTenant;

    public const PERMISSION_READ = 'read';

    public const PERMISSION_EDIT = 'edit';

    protected $fillable = [
        'tenant_id',
        'wiki_collection_id',
        'user_id',
        'permission',
        'invited_by',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(WikiCollection::class, 'wiki_collection_id');
    }

    /** @return array<int, string> */
    public static function permissions(): array
    {
        return [self::PERMISSION_READ, self::PERMISSION_EDIT];
    }

    public static function label(string $permission): string
    {
        return $permission === self::PERMISSION_EDIT ? 'Can edit' : 'Read only';
    }
}
