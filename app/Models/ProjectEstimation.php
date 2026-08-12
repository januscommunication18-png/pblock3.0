<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * A project's estimation system (Estimation §9/§36) — one per project, whatever its state.
 *
 * TENANT-SCOPED (CLAUDE.md §7). Deliberately has no `enabled` flag: whether estimation is
 * switched on is a project feature like Epics or Cycles, and lives in the project's feature
 * map. This row is the SYSTEM, and §25 requires it to outlive being switched off.
 */
class ProjectEstimation extends Model
{
    use BelongsToTenant;

    public const TYPE_POINTS = 'points';

    public const TYPE_CATEGORY = 'category';

    public const TYPE_TIME = 'time';

    protected $fillable = ['tenant_id', 'project_id', 'type', 'template', 'created_by'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function values(): HasMany
    {
        return $this->hasMany(EstimateValue::class)->orderBy('sort_order');
    }

    /** The values a picker may offer (§21: archived ones are history, not choices). */
    public function activeValues(): HasMany
    {
        return $this->values()->where('active', true);
    }

    /** How this system reads in a sentence — "Points · Fibonacci". */
    public function label(): string
    {
        $types = config('projects.estimate_types');
        $type = $types[$this->type] ?? null;
        $template = $type['templates'][$this->template]['label'] ?? null;

        return $template
            ? ($type['label'].' · '.$template)
            : ($type['label'] ?? $this->type);
    }
}
