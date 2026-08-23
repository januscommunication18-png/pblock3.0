<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A SaaS client (docs/features/backoffice-clients.md, §3). CENTRAL.
 *
 * A Client is a global USER (BC-D7): one row per person, keyed on `users.id`, with the email as
 * the human-readable identifier. Their tenants are reached through `workspace_memberships`,
 * which is also where their ROLE in each tenant comes from.
 *
 * Keyed on the id and not the email, deliberately: an address can change, and a primary
 * relationship that moves when somebody edits their profile is not a relationship.
 *
 * Deliberately NOT tenant-scoped. A Client is a fact about the platform, sitting above every
 * tenancy context — the Back Office reads it with no tenant initialised at all.
 */
class Client extends Model
{
    use SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_TRIAL = 'trial';

    public const STATUS_DISABLED = 'disabled';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_PENDING_DELETION = 'pending_deletion';

    /** @var array<int, string> */
    public const STATUSES = [
        self::STATUS_ACTIVE, self::STATUS_TRIAL, self::STATUS_DISABLED,
        self::STATUS_SUSPENDED, self::STATUS_CANCELLED, self::STATUS_PENDING_DELETION,
    ];

    /** How long a client sits in Pending Deletion before it may be removed for good (§19). */
    public const RETENTION_DAYS = 30;

    protected $fillable = [
        'code', 'user_id', 'name', 'phone', 'country', 'timezone',
        'status', 'disabled_at', 'pending_deletion_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'disabled_at' => 'datetime',
            'pending_deletion_at' => 'datetime',
        ];
    }

    /** The person this client IS. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Every membership this person holds — the join that defines their tenants AND their role
     * in each one.
     */
    public function membershipRows(): HasMany
    {
        return $this->hasMany(WorkspaceMembership::class, 'user_id', 'user_id');
    }

    /**
     * The tenants this client belongs to.
     *
     * Through memberships, NOT a foreign key on the tenant (BC-D7): a tenant has many clients —
     * everybody in it — so a column on `tenants` could never express this.
     *
     * `withoutGlobalScopes()` because the Back Office runs with no tenancy context at all.
     *
     * @return \Illuminate\Support\Collection<int, Workspace>
     */
    public function tenants()
    {
        if ($this->relationLoaded('membershipRows')) {
            $ids = $this->membershipRows->pluck('workspace_id');
        } else {
            $ids = $this->membershipRows()->pluck('workspace_id');
        }

        return Workspace::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $ids)
            ->with('workspaceSettings')
            ->orderBy('name')
            ->get();
    }

    /** The label every screen shows — the live user's name, or the snapshot if they are gone. */
    public function displayName(): string
    {
        return trim((string) ($this->user?->name ?: $this->name)) ?: (string) $this->email();
    }

    /** The human-readable identifier (BC-D7). Live, so a changed address is reflected at once. */
    public function email(): ?string
    {
        return $this->user?->email;
    }

    /**
     * When this person was last seen anywhere.
     *
     * Read from `sessions.last_activity`, which is the only real record of activity this
     * application keeps — there is no `last_login_at` on `users`. Sessions expire, so this is
     * frequently null, and the screens print "—" rather than inventing a date.
     */
    public function lastActiveAt(): ?\Illuminate\Support\Carbon
    {
        $ts = \Illuminate\Support\Facades\DB::table('sessions')
            ->where('user_id', $this->user_id)
            ->max('last_activity');

        return $ts ? \Illuminate\Support\Carbon::createFromTimestamp((int) $ts) : null;
    }

    public function activities(): HasMany
    {
        return $this->hasMany(ClientActivity::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * The next `CL-000128`.
     *
     * Derived from the highest existing code rather than from `count()`: a deleted client must
     * not free its number for reuse, or two different companies end up sharing an identifier in
     * somebody's records. `withTrashed()` for the same reason.
     */
    public static function nextCode(): string
    {
        $highest = (int) self::withTrashed()
            ->selectRaw('MAX(CAST(SUBSTRING(code, 4) AS UNSIGNED)) as n')
            ->value('n');

        return 'CL-'.str_pad((string) ($highest + 1), 6, '0', STR_PAD_LEFT);
    }

    /* ===== status ===================================================================== */

    /**
     * May this client's users use the product? (§17, §19)
     *
     * The one question the customer auth path asks. Trial counts as active — a trial is a
     * paying-customer-to-be, not a suspension.
     */
    public function allowsAccess(): bool
    {
        return in_array($this->status, [self::STATUS_ACTIVE, self::STATUS_TRIAL], true)
            && $this->deleted_at === null;
    }

    public function isDisabled(): bool
    {
        return $this->status === self::STATUS_DISABLED;
    }

    public function isPendingDeletion(): bool
    {
        return $this->status === self::STATUS_PENDING_DELETION;
    }

    /** When the retention window closes and permanent removal becomes possible (§19). */
    public function purgeableAt(): ?\Illuminate\Support\Carbon
    {
        return $this->pending_deletion_at?->copy()->addDays(self::RETENTION_DAYS);
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_PENDING_DELETION => 'Pending Deletion',
            default => ucfirst($status),
        };
    }

    /* ===== scopes ===================================================================== */

    /**
     * The §4 search: client name, contact name, contact email, workspace name, client code.
     *
     * The workspace clause is a `whereHas` rather than a join, so a client with three workspaces
     * appears ONCE — a join would return it three times and the list would look duplicated.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        $like = '%'.$term.'%';

        /*
         * Name and email come from the USER now (BC-D7), so the search reaches through the
         * relation rather than matching a copy on this table that could be stale.
         *
         * The tenant clause is `whereIn` over the membership join rather than a `join`, so a
         * client with three tenants appears ONCE — the duplication this whole change exists to
         * remove must not come back through the search box.
         */
        return $query->where(fn (Builder $q) => $q
            ->where('code', 'like', $like)
            ->orWhere('name', 'like', $like)
            ->orWhereHas('user', fn (Builder $u) => $u
                ->where('name', 'like', $like)
                ->orWhere('email', 'like', $like))
            ->orWhereIn('user_id', function ($sub) use ($like) {
                $sub->select('workspace_memberships.user_id')
                    ->from('workspace_memberships')
                    ->join('tenants', 'tenants.id', '=', 'workspace_memberships.workspace_id')
                    ->where('tenants.name', 'like', $like);
            }));
    }

    public function scopeStatus(Builder $query, ?string $status): Builder
    {
        return $status && in_array($status, self::STATUSES, true)
            ? $query->where('status', $status)
            : $query;
    }

    /**
     * Filter by an application the client has enabled somewhere (§5).
     *
     * "Has this client subscribed to Wiki?" means "does ANY of its workspaces have it on", which
     * is what `whereHas` asks. Projects is excluded from the flag lookup because it has no flag —
     * see `applications()`.
     */
    public function scopeApplication(Builder $query, ?string $app): Builder
    {
        if ($app === null || $app === '') {
            return $query;
        }

        $column = self::appColumn($app);

        /*
         * "Has this client subscribed to Wiki?" now means "does any tenant they BELONG TO have
         * it on" — reached through memberships, since the client no longer owns tenants.
         */
        $tenantIds = function ($sub) use ($column) {
            $sub->select('workspace_memberships.user_id')
                ->from('workspace_memberships')
                ->join('tenants', 'tenants.id', '=', 'workspace_memberships.workspace_id');

            if ($column !== null) {
                $sub->join('workspace_settings', 'workspace_settings.tenant_id', '=', 'tenants.id')
                    ->where('workspace_settings.'.$column, true);
            }
        };

        // Projects has no flag — it is the base application every tenant has — so any membership
        // qualifies. Client Hub has none because it does not exist yet, and matches nothing.
        if ($column === null && $app !== 'projects') {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('user_id', $tenantIds);
    }

    public function scopeCreatedWithin(Builder $query, ?string $range, ?string $from = null, ?string $to = null): Builder
    {
        return match ($range) {
            'today' => $query->whereDate('created_at', today()),
            '7d' => $query->where('created_at', '>=', now()->subDays(7)),
            '30d' => $query->where('created_at', '>=', now()->subDays(30)),
            'custom' => $query
                ->when($from, fn (Builder $q) => $q->whereDate('created_at', '>=', $from))
                ->when($to, fn (Builder $q) => $q->whereDate('created_at', '<=', $to)),
            default => $query,
        };
    }

    /* ===== applications (§10) ========================================================= */

    /**
     * The settings column behind an app key, or null when the app has no flag.
     *
     * Projects has none — it is the base application every workspace has — and Client Hub has
     * none because it does not exist yet. Both are honest nulls rather than invented columns.
     */
    public static function appColumn(string $key): ?string
    {
        return match ($key) {
            'wiki' => 'wiki_enabled',
            'helpdesk' => 'help_desk_enabled',
            default => null,
        };
    }

    /**
     * Which applications this client has subscribed to (§10).
     *
     * DERIVED from `workspace_settings`, never stored on the client (BC-D6): `wiki_enabled` and
     * `help_desk_enabled` are what the application itself reads, and a copy here would be a
     * second answer to "is Wiki on?" that could disagree with the product.
     *
     * @return array<int, array<string, mixed>>
     */
    public function applications(): array
    {
        $tenants = $this->tenants();

        $out = [];

        foreach ((array) config('workspace.apps') as $key => $app) {
            $column = self::appColumn($key);

            if ($key === 'projects') {
                // The base application: on wherever a tenant exists.
                $enabledIn = $tenants->count();
            } elseif ($column === null) {
                // Client Hub — `available => false` in config, so nobody can have it yet.
                $enabledIn = 0;
            } else {
                $enabledIn = $tenants->filter(fn ($t) => (bool) ($t->workspaceSettings->{$column} ?? false))->count();
            }

            $out[] = [
                'key' => $key,
                'label' => (string) $app['label'],
                'description' => (string) ($app['description'] ?? ''),
                'available' => (bool) ($app['available'] ?? false),
                'enabled' => $enabledIn > 0,
                'workspaces_enabled' => $enabledIn,
                'workspaces_total' => $tenants->count(),
            ];
        }

        return $out;
    }

    /* ===== usage (§13) ================================================================ */

    /**
     * The client-level usage summary.
     *
     * COUNTS only. The requirement's "18 / 25" needs a limit, and no plan, package or quota
     * exists anywhere in this application to read one from — so `limit` is null throughout and
     * the view renders a number rather than an invented denominator and a progress bar that
     * would be measuring against a figure somebody made up.
     *
     * @return array<int, array<string, mixed>>
     */
    public function usage(): array
    {
        $tenantIds = $this->tenants()->pluck('id');

        /*
         * "Users" is everybody ACROSS the tenants this person belongs to — their colleagues —
         * counted once each. Not a count of this client, which would always be 1.
         */
        $users = WorkspaceMembership::query()->whereIn('workspace_id', $tenantIds)->distinct('user_id')->count('user_id');
        $spaces = HelpCenterSpace::query()->withoutGlobalScopes()->whereIn('tenant_id', $tenantIds)->count();
        $projects = Project::query()->withoutGlobalScopes()->whereIn('tenant_id', $tenantIds)->count();

        return [
            ['label' => 'Tenants', 'value' => $tenantIds->count(), 'limit' => null],
            ['label' => 'Users across tenants', 'value' => $users, 'limit' => null],
            ['label' => 'Projects', 'value' => $projects, 'limit' => null],
            ['label' => 'Help Desk Spaces', 'value' => $spaces, 'limit' => null],
        ];
    }

    /**
     * Everybody this person shares a tenant with (§14's Users tab).
     *
     * Their colleagues, not themselves — the Users tab on a person's record answers "who else is
     * in their tenants", which is the only reading that is not a one-row table.
     */
    public function colleagues()
    {
        return WorkspaceMembership::query()
            ->whereIn('workspace_id', $this->tenants()->pluck('id'))
            ->with(['user', 'workspace']);
    }

    /**
     * This client's own membership rows, with the tenant loaded — the Tenants tab (§ "Tenants
     * Tab") and the expandable row on the list.
     */
    public function tenantMemberships()
    {
        return $this->membershipRows()->with('workspace')->get();
    }
}
