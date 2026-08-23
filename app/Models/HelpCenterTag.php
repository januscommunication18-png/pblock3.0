<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * One tag in a Space's vocabulary (docs/features/help-center.md, P14). TENANT-SCOPED.
 *
 * Tags belong to a SPACE, not to the workspace: one team's "Billing" is not another's, and a
 * shared list would mean every Space's picker growing with words its agents never use.
 */
class HelpCenterTag extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'help_center_space_id',
        'name',
        'name_key',
        'created_by',
    ];

    public function space(): BelongsTo
    {
        return $this->belongsTo(HelpCenterSpace::class, 'help_center_space_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The comparison form of a name.
     *
     * One function, used by the model when it writes and by the request when it checks, so
     * "is this a duplicate?" and "what gets stored?" cannot answer differently — which is the
     * only way a unique index turns into a 500 instead of a validation message.
     */
    public static function key(string $name): string
    {
        return mb_strtolower(trim($name));
    }

    /** Alphabetical, which is how a list somebody scans for a word should be ordered. */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('name');
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            // A table needs a second column to be a table rather than a list with borders, and
            // "when did this word enter our vocabulary?" is the one fact a tag has to offer.
            'created_at' => $this->created_at?->format('M j, Y'),
        ];
    }
}
