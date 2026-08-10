<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * Per-workspace settings singleton (spec §6-§11): feature toggles + wiki text.
 *
 * TENANT-SCOPED (CLAUDE.md §7): the BelongsToTenant global scope confines every query to
 * the active workspace and stamps `tenant_id` on insert. There is exactly one row per
 * workspace (unique tenant_id) — resolve it with WorkspaceSettingsManager.
 */
class WorkspaceSettings extends Model
{
    use BelongsToTenant;

    protected $table = 'workspace_settings';

    protected $fillable = [
        'tenant_id',
        'project_states_enabled',
        'releases_enabled',
        'initiatives_enabled',
        'teamspaces_enabled',
        'customers_enabled',
        'wiki_enabled',
        'teamspaces_locked_at',
        'wiki_description',
        'wiki_docs_url',
    ];

    protected function casts(): array
    {
        return [
            'project_states_enabled' => 'boolean',
            'releases_enabled' => 'boolean',
            'initiatives_enabled' => 'boolean',
            'teamspaces_enabled' => 'boolean',
            'customers_enabled' => 'boolean',
            'wiki_enabled' => 'boolean',
            'teamspaces_locked_at' => 'datetime',
        ];
    }

    /** Teamspaces is a one-way enable (spec §10): true once locked, never reversible. */
    public function teamspacesLocked(): bool
    {
        return $this->teamspaces_locked_at !== null;
    }
}
