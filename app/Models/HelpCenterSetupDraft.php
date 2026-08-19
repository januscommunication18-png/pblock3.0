<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * A six-step onboarding run in progress (docs/features/help-center.md, P2 §2, HC-D11).
 * TENANT-SCOPED.
 *
 * P2 §2: "Do not create the final Inbox/Help Desk configuration until Step 6 is confirmed."
 * Everything typed before that lives here and nowhere else — so abandoning the wizard at step 4
 * leaves the workspace with no Space at all, rather than a half-configured one already in the
 * navigation.
 *
 * A row rather than browser storage, because §2 also asks to preserve the draft when somebody
 * exits: `localStorage` loses it on another machine, and puts a workspace's configuration
 * somewhere the server cannot validate.
 *
 * One draft per USER per workspace. Two administrators setting up at the same time are filling
 * in two different forms; a single shared row would have them overwriting each other.
 */
class HelpCenterSetupDraft extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'step',
        'payload',
    ];

    protected $attributes = [
        'step' => 1,
    ];

    protected function casts(): array
    {
        return [
            'step' => 'integer',
            'payload' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * One step's saved values.
     *
     * @return array<string, mixed>
     */
    public function section(string $key): array
    {
        return (array) (($this->payload ?? [])[$key] ?? []);
    }

    /**
     * Merge one step's values in, leaving the other steps alone.
     *
     * Replaces that section wholesale rather than merging INTO it: a step that had three email
     * addresses and now has two has to end up with two, and a deep merge would resurrect the
     * one that was removed.
     *
     * @param  array<string, mixed>  $values
     */
    public function putSection(string $key, array $values): static
    {
        $payload = (array) ($this->payload ?? []);
        $payload[$key] = $values;

        $this->payload = $payload;

        return $this;
    }

    /**
     * Remember the furthest step reached, so returning resumes there (P2 §2).
     *
     * Only ever moves FORWARD. Going Back to fix step 1 must not throw away the fact that steps
     * 2 and 3 are already filled in — otherwise correcting a typo would cost the user the rest
     * of their run.
     */
    public function reach(int $step): static
    {
        $this->step = max($this->step, $step);

        return $this;
    }
}
