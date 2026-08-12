<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * One saved state of a page. TENANT-SCOPED.
 *
 * Append-mostly: rows are written by PageVersioner and only ever updated to coalesce a burst
 * of autosaves into the version already being written. Nothing edits an older version — a
 * history that can be rewritten is not a history.
 */
class ProjectPageVersion extends Model
{
    use BelongsToTenant;

    protected $table = 'project_page_versions';

    protected $fillable = [
        'tenant_id',
        'project_page_id',
        'title',
        'content',
        'status',
        'edited_by',
    ];

    public function page(): BelongsTo
    {
        return $this->belongsTo(ProjectPage::class, 'project_page_id');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by');
    }
}
