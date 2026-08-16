<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * One member's capacity override (§16). TENANT-SCOPED (CLAUDE.md §7).
 *
 * A row exists only when somebody works differently from the workspace default — the absence
 * of one IS "use workspace default" (CAP-D8). There is deliberately no `use_default` boolean:
 * with one, changing the workspace default would leave every member who is supposed to be
 * following it pinned to whatever it used to be.
 */
class MemberCapacity extends Model
{
    use BelongsToTenant;

    protected $table = 'member_capacities';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'hours_per_day',
        'working_days',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'hours_per_day' => 'decimal:2',
            'working_days' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
