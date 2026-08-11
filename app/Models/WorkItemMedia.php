<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * An image uploaded from a work item description editor.
 *
 * TENANT-SCOPED (CLAUDE.md §7): BelongsToTenant confines every query to the active workspace
 * and stamps `tenant_id` on insert. Project scoping is applied on top, because one workspace
 * holds many projects and the editor's gallery must only ever show this project's uploads.
 */
class WorkItemMedia extends Model
{
    use BelongsToTenant;

    protected $table = 'work_item_media';

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

    /** The authorized URL the editor embeds — never a direct path to the file on disk. */
    public function url(): string
    {
        return route('projects.work-items.media.show', [
            'project' => $this->project_id,
            'media' => $this->id,
        ]);
    }
}
