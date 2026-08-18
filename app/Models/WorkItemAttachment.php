<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * A file attached to a work item (docs/features/work-item-attachments.md).
 *
 * TENANT-SCOPED (CLAUDE.md §7): BelongsToTenant confines every query to the active workspace and
 * stamps `tenant_id` on insert. Project scoping is applied on top, because authorization for
 * reading the file resolves through the project, not the workspace.
 *
 * Compare WorkItemMedia: that is an image the description editor inlined into the body HTML and
 * whose URL is baked into that markup. This is a file listed against the item, so its URL is only
 * ever read from this row — which is what lets an attachment be deleted without editing prose.
 */
class WorkItemAttachment extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'project_id',
        'work_item_id',
        'uploaded_by',
        'disk',
        'path',
        'name',
        'mime',
        'size',
    ];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function workItem(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** The authorized download URL — never a direct path to the file on disk. */
    public function url(): string
    {
        return route('projects.work-items.attachments.show', [
            'project' => $this->project_id,
            'workItem' => $this->work_item_id,
            'attachment' => $this->id,
        ]);
    }
}
