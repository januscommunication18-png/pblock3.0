<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * The top-level organizational container inside the Help Center
 * (docs/features/help-center.md §3). TENANT-SCOPED.
 *
 * A workspace may hold many — Customer Support, Billing, Partner Support — and each holds its
 * own Inboxes (§18). The Space is also where responsibility sits: its Lead may manage it and
 * everything under it without being a workspace administrator (§19).
 */
class HelpCenterSpace extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'name',
        'description',
        'types',
        'department_groups',
        'lead_user_id',
        'created_by',
        'position',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'types' => 'array',
            'department_groups' => 'array',
            'position' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    public function inboxes(): HasMany
    {
        return $this->hasMany(HelpCenterInbox::class);
    }

    /** The support group (P2 §7). */
    public function members(): HasMany
    {
        return $this->hasMany(HelpCenterSpaceMember::class);
    }

    /** The conversation workflow, Open first and Closed last (P2 §16). */
    public function statuses(): HasMany
    {
        return $this->hasMany(HelpCenterStatus::class)->orderBy('position')->orderBy('id');
    }

    /** How this Space behaves (P2 §17-§24). One row, or none until Step 6 has run. */
    public function settings(): HasOne
    {
        return $this->hasOne(HelpCenterSpaceSettings::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lead_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Everything still in active navigation. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /**
     * May this person change the Space — its details, its Inboxes, their email addresses?
     *
     * The two authorities of §19, in one sentence: whoever runs the workspace, and whoever runs
     * this Space. Stated on the model so the policy, the controllers and the buttons that offer
     * the actions cannot give three different answers.
     *
     * Deliberately NOT "created it". Creating a Space is a one-off act; leading it is a standing
     * responsibility, and §3 makes the Lead "the primary person responsible for the Space".
     */
    public function manageableBy(User $user): bool
    {
        if ((int) $this->lead_user_id === (int) $user->id) {
            return true;
        }

        return $user->currentWorkspace !== null
            && $user->can('manageSettings', $user->currentWorkspace);
    }

    /**
     * The Space's types, as typed (HC-D9).
     *
     * Free text and stored verbatim, so this is just the column — there is no key to translate
     * into a label, which is the point of dropping the fixed vocabulary.
     *
     * @return array<int, string>
     */
    public function typeList(): array
    {
        return array_values(array_filter((array) $this->types));
    }

    /**
     * The Space's Department Groups (P2 §6, HC-D13).
     *
     * Same shape, same storage and same normalization as types — they are labels a workspace
     * invents, and Step 2 assigns coworkers to them by name. Optional: a Space with one team
     * has no groups to name, so an empty list is a legitimate answer rather than a gap.
     *
     * @return array<int, string>
     */
    public function groupList(): array
    {
        return array_values(array_filter((array) $this->department_groups));
    }

    /** The types as one readable string, for the places that have a line rather than a row. */
    public function typeLabel(): string
    {
        $types = $this->typeList();

        return $types === [] ? '—' : implode(', ', $types);
    }

    /**
     * Trim, collapse inner whitespace, drop blanks, and remove case-insensitive duplicates —
     * keeping the FIRST spelling of each.
     *
     * Here rather than only in the form request, because every path that writes types must
     * normalize identically: "Billing" and " billing " are one type to anybody reading the
     * Space, and two rows in the JSON is two chips that look like a mistake.
     *
     * @param  array<int, mixed>  $values
     * @return array<int, string>
     */
    public static function normalizeTypes(array $values): array
    {
        $out = [];
        $seen = [];

        foreach ($values as $value) {
            if (! is_string($value) && ! is_numeric($value)) {
                continue;
            }

            $value = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');

            if ($value === '') {
                continue;
            }

            $key = mb_strtolower($value);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $out[] = $value;
        }

        return $out;
    }

    /**
     * What the navigation and the wizard need to draw a Space.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'types' => $this->typeList(),
            'type_label' => $this->typeLabel(),
            'department_groups' => $this->groupList(),
            'lead' => $this->relationLoaded('lead') && $this->lead
                ? [
                    'id' => $this->lead->id,
                    'name' => $this->lead->displayName(),
                    'email' => $this->lead->email,
                    'avatar' => $this->lead->avatar_url,
                    // The Projects card draws a coloured initial when there is no photo;
                    // `$pb.avatarColor` keys off the id, so the two agree about the colour.
                    'initial' => mb_strtoupper(mb_substr($this->lead->displayName(), 0, 1)),
                ]
                : null,
        ];
    }
}
