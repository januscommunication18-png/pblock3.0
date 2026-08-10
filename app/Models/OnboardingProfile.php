<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnboardingProfile extends Model
{
    public const STEP_PROFILE = 'profile';

    public const STEP_ROLE = 'role';

    public const STEP_GOALS = 'goals';

    public const STEP_COMPLETED = 'completed';

    protected $fillable = [
        'user_id',
        'role_selection',
        'goals',
        'current_step',
        'completed_at',
        'workspace_setup_completed_at',
    ];

    protected function casts(): array
    {
        return [
            'goals' => 'array',
            'completed_at' => 'datetime',
            'workspace_setup_completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isCompleted(): bool
    {
        return $this->current_step === self::STEP_COMPLETED;
    }

    /** The first-workspace invite step has been finished or explicitly skipped (spec §9). */
    public function hasCompletedWorkspaceSetup(): bool
    {
        return $this->workspace_setup_completed_at !== null;
    }
}
