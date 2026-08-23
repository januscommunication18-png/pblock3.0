<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * One of a Space's outgoing email templates (docs/features/help-center.md, P48). TENANT-SCOPED.
 *
 * A row here is an OVERRIDE. The absence of one means the packaged default is in use — see the
 * migration for why that is the design rather than seeding three rows per Space.
 */
class HelpCenterEmailTemplate extends Model
{
    use BelongsToTenant;

    public const TYPE_AUTO_RESPONSE = 'auto_response';

    public const TYPE_AGENT_REPLY = 'agent_reply';

    public const TYPE_TICKET_LAYOUT = 'ticket_layout';

    public const TYPE_RATING_REQUEST = 'rating_request';

    protected $fillable = [
        'tenant_id',
        'help_center_space_id',
        'type',
        'name',
        'subject',
        'body',
        'enabled',
    ];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(HelpCenterSpace::class, 'help_center_space_id');
    }

    /** The three types, as configured. */
    public static function types(): array
    {
        return (array) config('help-center.email_templates');
    }

    public static function isType(?string $type): bool
    {
        return $type !== null && array_key_exists($type, self::types());
    }

    /** The packaged default for a type — what a Space uses until it overrides it. */
    public static function default(string $type): array
    {
        return (array) (self::types()[$type] ?? []);
    }

    /**
     * May this type be switched off?
     *
     * False for the agent reply: the requirement says it "should remain available whenever an
     * agent replies", so its `enabled` column is ignored rather than checked-and-special-cased
     * at each of the places that send mail.
     */
    public static function canDisable(string $type): bool
    {
        return (bool) (self::default($type)['can_disable'] ?? false);
    }
}
