<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/** Time logged against a work item (spec §9). TENANT-SCOPED. Duration is total minutes. */
class WorkItemWorklog extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'project_id', 'work_item_id', 'user_id', 'created_by',
        'work_date', 'minutes_logged', 'description',
    ];

    protected function casts(): array
    {
        return ['work_date' => 'date', 'minutes_logged' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** "2h 30m" — the display format for a stored minute count (§9.6/§9.9). */
    public static function humanDuration(int $minutes): string
    {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        if ($h > 0 && $m > 0) {
            return "{$h}h {$m}m";
        }

        return $h > 0 ? "{$h}h" : "{$m}m";
    }

    public function duration(): string
    {
        return self::humanDuration((int) $this->minutes_logged);
    }
}
