<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * An external URL attached to a work item (Collaboration spec §37–§41).
 * TENANT-SCOPED (CLAUDE.md §7).
 */
class WorkItemLink extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'project_id',
        'work_item_id',
        'url',
        'title',
        'created_by',
    ];

    public function workItem(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** What to show when the author gave no title (§38): the link's domain. */
    public function label(): string
    {
        if (filled($this->title)) {
            return (string) $this->title;
        }

        $host = parse_url((string) $this->url, PHP_URL_HOST);

        return $host ? preg_replace('/^www\./', '', $host) : (string) $this->url;
    }
}
