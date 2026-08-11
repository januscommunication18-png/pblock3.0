<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * A project-level audit event (Project Member Management §29). TENANT-SCOPED, append-only.
 *
 * Currently membership events; the same table is the natural home for other project-level
 * events (archive, restore, settings changes) when those need an audit trail.
 */
class ProjectActivity extends Model
{
    use BelongsToTenant;

    protected $table = 'project_activity';

    public const EVENT_MEMBER_ADDED = 'member_added';

    public const EVENT_MEMBER_REMOVED = 'member_removed';

    public const EVENT_MEMBER_ROLE_CHANGED = 'member_role_changed';

    protected $fillable = [
        'tenant_id',
        'project_id',
        'actor_id',
        'event',
        'target_user_id',
        'target_name',
        'old_role',
        'new_role',
        'meta',
    ];

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    /**
     * The event as the sentence §29 asks for, e.g.
     * "Rohit Philip added John Smith to Website Redesign as Contributor."
     */
    public function sentence(): string
    {
        $actor = $this->actor?->displayName() ?? 'Someone';
        $target = $this->target_name ?? $this->targetUser?->displayName() ?? 'a member';
        $project = $this->project?->name ?? 'the project';
        $label = fn (?string $role) => $role ? (config("projects.roles.{$role}.label") ?? ucfirst($role)) : '—';

        return match ($this->event) {
            self::EVENT_MEMBER_ADDED => "{$actor} added {$target} to {$project} as {$label($this->new_role)}.",
            self::EVENT_MEMBER_REMOVED => "{$actor} removed {$target} from {$project}.",
            self::EVENT_MEMBER_ROLE_CHANGED => "{$actor} changed {$target}'s role from {$label($this->old_role)} to {$label($this->new_role)}.",
            default => "{$actor} updated {$project}.",
        };
    }
}
