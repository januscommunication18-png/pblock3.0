<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * One customer thread inside a Space (docs/features/help-center.md, P6). TENANT-SCOPED.
 *
 * Carries `help_center_space_id` as well as `tenant_id` so P3 §16's rule can be enforced
 * directly — "validate that conversation.space_id == current_space.id" — without a join through
 * the Inbox.
 */
class HelpCenterConversation extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'help_center_space_id',
        'help_center_inbox_id',
        'help_center_status_id',
        'assignee_id',
        'subject',
        'customer_email',
        'customer_name',
        'thread_key',
        'last_message_at',
        'is_spam',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'closed_at' => 'datetime',
            'is_spam' => 'boolean',
        ];
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(HelpCenterSpace::class, 'help_center_space_id');
    }

    public function inbox(): BelongsTo
    {
        return $this->belongsTo(HelpCenterInbox::class, 'help_center_inbox_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(HelpCenterStatus::class, 'help_center_status_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(HelpCenterMessage::class)->orderBy('received_at')->orderBy('id');
    }

    /** The customer, as they wrote — name when we have one, address otherwise. */
    public function customerLabel(): string
    {
        return $this->customer_name ?: $this->customer_email;
    }

    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }

    // ---- the six system views of §16, as query scopes -----------------------------------------
    //
    // Written here rather than in a controller because each one is a definition of a word the
    // product uses, and two screens asking "what is Unassigned?" must not answer differently.

    public function scopeForSpace(Builder $query, int $spaceId): Builder
    {
        return $query->where('help_center_space_id', $spaceId);
    }

    public function scopeUnassigned(Builder $query): Builder
    {
        return $query->whereNull('assignee_id')->whereNull('closed_at')->where('is_spam', false);
    }

    public function scopeAssignedTo(Builder $query, int $userId): Builder
    {
        return $query->where('assignee_id', $userId)->whereNull('closed_at')->where('is_spam', false);
    }

    public function scopeAssigned(Builder $query): Builder
    {
        return $query->whereNotNull('assignee_id')->whereNull('closed_at')->where('is_spam', false);
    }

    public function scopeClosed(Builder $query): Builder
    {
        return $query->whereNotNull('closed_at')->where('is_spam', false);
    }

    public function scopeSpam(Builder $query): Builder
    {
        return $query->where('is_spam', true);
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'subject' => $this->subject ?: '(no subject)',
            'customer' => $this->customerLabel(),
            'customer_email' => $this->customer_email,
            'status' => $this->relationLoaded('status') && $this->status
                ? ['name' => $this->status->name, 'color' => $this->status->color]
                : null,
            'assignee' => $this->relationLoaded('assignee') && $this->assignee
                ? $this->assignee->displayName()
                : null,
            'last_message_at' => $this->last_message_at?->diffForHumans(),
            'closed' => $this->isClosed(),
            'is_spam' => $this->is_spam,
        ];
    }
}
