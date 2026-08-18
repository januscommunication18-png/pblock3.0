<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * One person's place in a Help Desk (docs/features/help-desk.md, FR-1.4/1.6/1.8). TENANT-SCOPED.
 *
 * Independent of `WorkspaceMembership` on purpose: a workspace admin is not an agent, and an
 * agent is nothing outside the Help Desk. §5's rule is that workspace role, project role, Help
 * Desk role and inbox access are evaluated separately and the most restrictive wins — which
 * only works if this row is the ONLY thing that says what somebody may do in here.
 *
 * SOFT DELETED: removing somebody must preserve their replies, notes and assignments
 * (acceptance criterion 5), and those point at this row.
 */
class HelpDeskMember extends Model
{
    use BelongsToTenant, SoftDeletes;

    public const ROLE_ADMIN = 'admin';

    public const ROLE_MANAGER = 'manager';

    public const ROLE_AGENT = 'agent';

    public const ROLE_COLLABORATOR = 'collaborator';

    public const ROLE_VIEWER = 'viewer';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = [
        'tenant_id',
        'help_desk_id',
        'user_id',
        'role',
        'status',
        'created_by',
    ];

    public function helpDesk(): BelongsTo
    {
        return $this->belongsTo(HelpDesk::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The inboxes named for this member. Meaningless for roles that reach all of them. */
    public function inboxes(): BelongsToMany
    {
        return $this->belongsToMany(HelpDeskInbox::class, 'help_desk_member_inboxes')
            ->withTimestamps();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * May this member do that, by role?
     *
     * Deactivation is checked here as well as by the caller: an inactive member keeps their
     * role and their inbox access so both survive being switched back on, and an ability check
     * that only read the role would let a deactivated person keep working (FR-1.8).
     */
    public function can(string $ability): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        return in_array($ability, self::config($this->role)['abilities'] ?? [], true);
    }

    /** Does this member's role reach every inbox, present and future (FR-1.7)? */
    public function reachesAllInboxes(): bool
    {
        return (bool) (self::config($this->role)['all_inboxes'] ?? false);
    }

    public function rank(): int
    {
        return self::rankOf($this->role);
    }

    /** Where a role sits, for checks made before a membership exists. Unknown roles rank lowest. */
    public static function rankOf(?string $role): int
    {
        return (int) (self::config($role)['rank'] ?? 0);
    }

    /** The role keys, highest authority first — the order the UI offers them in. */
    /** @return array<int, string> */
    public static function roles(): array
    {
        return collect(config('help-desk.roles', []))
            ->sortByDesc('rank')
            ->keys()
            ->all();
    }

    public static function label(?string $role): string
    {
        return (string) (self::config($role)['label'] ?? ucfirst((string) $role));
    }

    /** @return array<string, mixed> */
    private static function config(?string $role): array
    {
        return (array) config('help-desk.roles.'.$role, []);
    }
}
