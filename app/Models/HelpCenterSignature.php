<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * An agent's signature, or a Space's default one (P48). TENANT-SCOPED.
 *
 * `user_id` null means the Space default. See the migration for why both live in one table.
 *
 * NOTE ON THE UNIQUE INDEX: `(help_center_space_id, user_id)` does NOT constrain the default row
 * on MySQL, because MySQL treats NULLs as distinct and will happily accept a second row with the
 * same Space and a null user. The single-default rule is therefore held by `updateOrCreate` in
 * `SignatureResolver`, whose `where user_id is null` matches correctly — the index is a real
 * constraint for agents and a documented near-miss for the default. Said plainly here because a
 * unique index that looks like it covers a case it does not is worse than no index at all.
 */
class HelpCenterSignature extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'help_center_space_id',
        'user_id',
        'enabled',
        'name',
        'job_title',
        'company',
        'avatar_url',
        'content',
    ];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** The Space's fallback rather than one person's. */
    public function isDefault(): bool
    {
        return $this->user_id === null;
    }

    /**
     * Is there anything here worth appending?
     *
     * An enabled signature with every field blank is not a signature — appending it would add an
     * empty block to the bottom of every reply, and the resolver should fall through to the
     * Space default instead of stopping at a row that says nothing.
     */
    public function hasContent(): bool
    {
        if (! $this->enabled) {
            return false;
        }

        return trim(strip_tags((string) $this->content)) !== ''
            || trim((string) $this->name) !== ''
            || trim((string) $this->job_title) !== ''
            || trim((string) $this->company) !== ''
            || trim((string) $this->avatar_url) !== '';
    }

    /**
     * The signature as the HTML that goes into `{{agent_signature}}`.
     *
     * Built here rather than in a Blade view because it is substituted into a template body as a
     * string — there is no view being rendered at that point, only text being assembled.
     *
     * `content` is emitted raw because it was sanitized on the way in (P41); everything else is
     * a plain field and is escaped. That split is the same one the whole module runs on.
     */
    public function toHtml(): string
    {
        if (! $this->hasContent()) {
            return '';
        }

        $rows = [];

        if (trim((string) $this->avatar_url) !== '') {
            $rows[] = '<img src="'.e($this->avatar_url).'" alt="" width="48" height="48" '
                .'style="border-radius:24px;display:block;margin-bottom:8px;" />';
        }

        if (trim((string) $this->name) !== '') {
            $rows[] = '<div style="font-size:14px;font-weight:600;color:#23272f;">'.e($this->name).'</div>';
        }

        if (trim((string) $this->job_title) !== '') {
            $rows[] = '<div style="font-size:13px;color:#6b7280;">'.e($this->job_title).'</div>';
        }

        if (trim((string) $this->company) !== '') {
            $rows[] = '<div style="font-size:13px;color:#6b7280;">'.e($this->company).'</div>';
        }

        if (trim(strip_tags((string) $this->content)) !== '') {
            $rows[] = '<div style="font-size:13px;color:#6b7280;line-height:1.6;">'.$this->content.'</div>';
        }

        return '<div style="margin-top:20px;">'.implode('', $rows).'</div>';
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'is_default' => $this->isDefault(),
            'enabled' => (bool) $this->enabled,
            'name' => $this->name,
            'job_title' => $this->job_title,
            'company' => $this->company,
            'avatar_url' => $this->avatar_url,
            'content' => $this->content,
            'html' => $this->toHtml(),
        ];
    }
}
