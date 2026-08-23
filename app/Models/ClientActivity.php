<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing that happened to a client (docs/features/backoffice-clients.md, §15). CENTRAL.
 */
class ClientActivity extends Model
{
    public const UPDATED_AT = null;

    public const CREATED = 'CLIENT_CREATED';

    public const DISABLED = 'CLIENT_DISABLED';

    public const ENABLED = 'CLIENT_ENABLED';

    public const DELETE_REQUESTED = 'CLIENT_DELETE_REQUESTED';

    public const RESTORED = 'CLIENT_RESTORED';

    public const PASSWORD_RESET_REQUESTED = 'CLIENT_PASSWORD_RESET_REQUESTED';

    public const WORKSPACE_CREATED = 'WORKSPACE_CREATED';

    public const APPLICATION_ENABLED = 'CLIENT_APPLICATION_ENABLED';

    public const APPLICATION_DISABLED = 'CLIENT_APPLICATION_DISABLED';

    /** Role, access or membership changed in ONE tenant. */
    public const TENANT_CHANGED = 'CLIENT_TENANT_CHANGED';

    protected $fillable = [
        'client_id', 'backoffice_user_id', 'action', 'description',
        'old_value', 'new_value', 'meta',
    ];

    protected function casts(): array
    {
        return ['meta' => 'array', 'created_at' => 'datetime'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(BackofficeUser::class, 'backoffice_user_id');
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'description' => $this->description,
            'old_value' => $this->old_value,
            'new_value' => $this->new_value,
            // "System" rather than a blank: a feed entry with no name reads as a rendering fault
            // when it is in fact the truth about who did it.
            'actor' => $this->actor?->name ?? 'System',
            'at' => $this->created_at?->format('M j, Y g:i A'),
            'day' => $this->created_at?->format('M j, Y'),
        ];
    }
}
