<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * How a Space behaves (docs/features/help-center.md, P2 §17–§24). TENANT-SCOPED.
 *
 * One row per Space, kept off `help_center_spaces` (HC-D15): twelve behaviour fields on the
 * Space row would make the Space mostly settings, and this keeps "what a Space IS" separate
 * from "how it behaves".
 *
 * The reassignment and auto-follow settings are STORED but not yet acted upon (HC-D17) — both
 * operate on conversations, which do not exist. What is captured here is what the user
 * configured; the runtime arrives with the conversation work.
 */
class HelpCenterSpaceSettings extends Model
{
    use BelongsToTenant;

    protected $table = 'help_center_space_settings';

    public const DESTINATION_UNASSIGNED = 'unassigned';

    public const DESTINATION_AVAILABLE_AGENT = 'available_agent';

    protected $fillable = [
        'tenant_id',
        'help_center_space_id',
        'metadata',
        'auto_bcc_enabled',
        'auto_bcc_email',
        'reassign_enabled',
        'reassign_after_minutes',
        'reassign_destination',
        'auto_follow_mentions',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'auto_bcc_enabled' => 'boolean',
            'reassign_enabled' => 'boolean',
            'reassign_after_minutes' => 'integer',
            'auto_follow_mentions' => 'boolean',
        ];
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(HelpCenterSpace::class, 'help_center_space_id');
    }

    /**
     * The metadata toggles as they start out (P2 §18).
     *
     * `ai_tag` is present and false, and stays false: it is "Coming Soon" and is refused
     * server-side, not merely disabled in the UI (HC-D18).
     *
     * @return array<string, bool>
     */
    public static function defaultMetadata(): array
    {
        $out = [];

        foreach ((array) config('help-center.metadata') as $key => $meta) {
            $out[$key] = (bool) ($meta['default'] ?? false);
        }

        return $out;
    }

    /** @return array<int, string> */
    public static function destinations(): array
    {
        return [self::DESTINATION_UNASSIGNED, self::DESTINATION_AVAILABLE_AGENT];
    }

    /**
     * The threshold split for the UI (P2 §21).
     *
     * Stored as total minutes (HC-D16) because it is a duration; the Hours + Minutes pair is
     * presentation, and splitting it here means the component does not each invent its own
     * arithmetic.
     *
     * @return array{hours: int, minutes: int}
     */
    public function threshold(): array
    {
        $total = max(0, (int) $this->reassign_after_minutes);

        return ['hours' => intdiv($total, 60), 'minutes' => $total % 60];
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'metadata' => (array) $this->metadata,
            'auto_bcc_enabled' => $this->auto_bcc_enabled,
            'auto_bcc_email' => $this->auto_bcc_email,
            'reassign_enabled' => $this->reassign_enabled,
            'reassign_after' => $this->threshold(),
            'reassign_destination' => $this->reassign_destination,
            'auto_follow_mentions' => $this->auto_follow_mentions,
        ];
    }
}
