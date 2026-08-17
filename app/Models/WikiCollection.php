<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * A group of related Wiki pages (docs/features/wiki.md). TENANT-SCOPED.
 *
 * Belongs to the workspace rather than to any project — that is the line between Wiki and
 * Project Pages: one preserves knowledge for the organization, the other documents a project.
 */
class WikiCollection extends Model
{
    use BelongsToTenant;

    public const VISIBILITY_PUBLIC = 'public';

    public const VISIBILITY_PRIVATE = 'private';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_UNPUBLISHED = 'unpublished';

    /**
     * Defaults the MODEL knows, not only the column.
     *
     * A `create()` returns the instance it was given, without the values the database filled
     * in — so a freshly created collection read back `null` for its status while the row said
     * `draft`. Stating them here means the object and the row agree from the first moment.
     */
    protected $attributes = [
        'visibility' => self::VISIBILITY_PUBLIC,
        'status' => self::STATUS_DRAFT,
    ];

    protected $fillable = [
        'tenant_id',
        'name',
        'description',
        'visibility',
        'status',
        'public_slug',
        'published_at',
        'created_by',
        'position',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'archived_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function members(): HasMany
    {
        return $this->hasMany(WikiCollectionMember::class);
    }

    /**
     * Whoever runs this collection: the person who made it, or whoever runs the workspace.
     *
     * The gate behind changing what the collection IS and who may see it. Stated here so the
     * endpoints and the buttons that offer them cannot disagree about the answer.
     */
    public function manageableBy(User $user): bool
    {
        return (int) $this->created_by === (int) $user->id
            || ($user->currentWorkspace !== null
                && $user->can('manageSettings', $user->currentWorkspace));
    }

    /**
     * May this person open the collection?
     *
     * Public means the whole workspace. Private means the people named on it — plus whoever
     * created it, who would otherwise be locked out of their own collection the moment they
     * made it private.
     *
     * Archiving NARROWS that, and never widens it. A retired collection is left to the people
     * who can bring it back; everybody else's invitation stops opening it, because an archive
     * half the workspace can still walk into is a filing cabinet with no lock. The membership
     * rows stay exactly where they are, so restoring hands the collection back to the same
     * people — that is the whole difference between archiving and deleting.
     *
     * The `&&` is load-bearing. `archived || manageableBy` would let a workspace admin who is
     * not on a private collection archive it and thereby be able to READ it, which would make
     * archiving a way to get into something you were never invited to.
     */
    public function openableBy(User $user): bool
    {
        if ($this->archived_at !== null && ! $this->manageableBy($user)) {
            return false;
        }

        if (! $this->isPrivate()) {
            return true;
        }

        return (int) $this->created_by === (int) $user->id
            || $this->members()->where('user_id', $user->id)->exists();
    }

    /**
     * May this person change what is in it — pages, their arrangement, the cover?
     *
     * Reading is not writing: a public collection opens for the whole workspace and still only
     * changes for the people granted `edit`.
     *
     * Delegates to openableBy() rather than restating it, so the archive rule is written once.
     * An `edit` member of an archived collection cannot open it, and therefore cannot write to
     * it; its creator still can. Archiving narrows WHO, not what they may do.
     */
    public function writableBy(User $user): bool
    {
        if (! $this->openableBy($user)) {
            return false;
        }

        return $this->manageableBy($user)
            || $this->members()
                ->where('user_id', $user->id)
                ->where('permission', WikiCollectionMember::PERMISSION_EDIT)
                ->exists();
    }

    /**
     * The query-level twin of openableBy() — every collection this person may open.
     *
     * Every LIST of collections goes through here, because a row somebody cannot open must not
     * be named to them either: a private collection that appears in the sidebar and then answers
     * 403 has already told them it exists, which is the one thing "private" was there to
     * prevent. Read this beside openableBy(); if either moves, both do.
     *
     * Composed with the state scope rather than folding one into the other —
     * `->active()->visibleTo($user)` for the live lists, `->archived()->visibleTo($user)` for
     * the Archived section.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        // Public to the workspace, made by this person, or invited to it. Grouped, so the ORs
        // cannot leak out across whatever else the caller has already constrained.
        // `!= private` rather than `= public`, so a third visibility behaves here exactly as
        // it would in isPrivate().
        $query->where(fn (Builder $q) => $q
            ->where($query->qualifyColumn('visibility'), '!=', self::VISIBILITY_PRIVATE)
            ->orWhere($query->qualifyColumn('created_by'), $user->id)
            ->orWhereIn(
                $query->qualifyColumn('id'),
                WikiCollectionMember::query()->where('user_id', $user->id)->select('wiki_collection_id'),
            ));

        // Whoever runs the workspace keeps every archived collection they could already open.
        // This lifts ONLY the archive restriction: an admin still does not see private
        // collections they were never invited to, which is what the visibility dialog promises.
        if ($user->currentWorkspace !== null && $user->can('manageSettings', $user->currentWorkspace)) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->whereNull($query->qualifyColumn('archived_at'))
            ->orWhere($query->qualifyColumn('created_by'), $user->id));
    }

    /** Everything still in active navigation (§"Archive instead of delete"). */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    /** Live at its public URL right now. */
    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED && $this->public_slug !== null;
    }

    /**
     * The two answers to "who can see it", with the copy the pickers show.
     *
     * Stated once: the create modal and the collection's edit dialog are two screens asking the
     * same question, and two copies of the wording is two copies free to disagree.
     *
     * @return array<int, array<string, string>>
     */
    public static function visibilityOptions(): array
    {
        return [
            ['value' => self::VISIBILITY_PUBLIC, 'label' => 'Public',
                'desc' => 'Everyone in the workspace can find and open it.'],
            ['value' => self::VISIBILITY_PRIVATE, 'label' => 'Private',
                'desc' => 'Only people invited to the collection can open it.'],
        ];
    }

    /** @return array<int, string> */
    public static function statuses(): array
    {
        return [self::STATUS_DRAFT, self::STATUS_PUBLISHED, self::STATUS_UNPUBLISHED];
    }

    public function isPrivate(): bool
    {
        return $this->visibility === self::VISIBILITY_PRIVATE;
    }

    /** @return array<string, mixed> */
    public function toCard(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'visibility' => $this->visibility,
            'status' => $this->status,
            'public_slug' => $this->public_slug,
            'published' => $this->isPublished(),
            'position' => $this->position,
            'archived' => $this->archived_at !== null,
        ];
    }
}
