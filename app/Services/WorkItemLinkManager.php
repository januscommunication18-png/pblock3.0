<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkItemActivity;
use App\Models\WorkItemLink;
use Illuminate\Support\Facades\DB;

/**
 * External links on a work item (Collaboration spec §37–§41).
 *
 * URL validation lives in the form request; what lives here is the part that must hold
 * however the link was created: every add, edit and remove writes an activity entry (§45),
 * and deleting a link only ever removes the association (§41) — there is nothing else to
 * delete, but saying so keeps the intent explicit.
 */
class WorkItemLinkManager
{
    public function __construct(private readonly WorkItemActivityRecorder $activity) {}

    public function create(WorkItem $item, User $actor, string $url, ?string $title): WorkItemLink
    {
        return DB::transaction(function () use ($item, $actor, $url, $title) {
            /** @var WorkItemLink $link */
            $link = WorkItemLink::create([
                'project_id' => $item->project_id,
                'work_item_id' => $item->id,
                'url' => $url,
                'title' => $title ?: null,
                'created_by' => $actor->id,
            ]);

            $this->log($item, $actor, 'link_added', null, $link->label());

            return $link;
        });
    }

    public function update(WorkItemLink $link, User $actor, string $url, ?string $title): WorkItemLink
    {
        return DB::transaction(function () use ($link, $actor, $url, $title) {
            $before = $link->label();

            $link->forceFill(['url' => $url, 'title' => $title ?: null])->save();

            if ($link->workItem) {
                $this->log($link->workItem, $actor, 'link_edited', $before, $link->label());
            }

            return $link;
        });
    }

    public function delete(WorkItemLink $link, User $actor): void
    {
        DB::transaction(function () use ($link, $actor) {
            $item = $link->workItem;
            $label = $link->label();

            $link->delete();

            if ($item) {
                $this->log($item, $actor, 'link_removed', $label, null);
            }
        });
    }

    private function log(WorkItem $item, User $actor, string $field, ?string $old, ?string $new): void
    {
        $this->activity->record($item, $actor, WorkItemActivity::EVENT_UPDATED, [
            'field' => $field,
            'old_value' => $old,
            'new_value' => $new,
        ]);
    }
}
