<?php

namespace App\Services\HelpCenter;

use App\Models\HelpCenterSpace;
use App\Models\HelpCenterSpaceMember;
use App\Models\HelpCenterStatus;
use Illuminate\Support\Facades\DB;

/**
 * Saving an edited workflow onto a Space that already has one
 * (docs/features/help-center.md, P16).
 *
 * The difference from `SetupCommitter::createStatuses()` is the only thing that matters here:
 * that runs once, on a Space with no statuses, and can create every row. This runs on a Space
 * whose statuses are already POINTED AT — `help_center_requests.help_center_status_id` — so a
 * delete-and-recreate would silently detach every Request in the Inbox from its status.
 *
 * So rows are matched by id and updated in place; only rows the editor actually removed are
 * deleted, and only ones it actually added are created.
 */
class WorkflowUpdater
{
    /**
     * @param  array<int, array<string, mixed>>  $rows  Already normalized by WorkflowStatusPayload.
     */
    public function sync(HelpCenterSpace $space, array $rows): void
    {
        $ordered = WorkflowStatusPayload::ordered($rows);

        $existing = $space->statuses()->get()->keyBy('id');
        $members = $this->memberIds($space);
        $kept = [];

        DB::transaction(function () use ($space, $ordered, $existing, $members, &$kept) {
            foreach ($ordered as $row) {
                $assignees = array_values(array_intersect($row['default_assignees'], $members));

                $attributes = [
                    'name' => $row['name'],
                    'color' => $row['color'],
                    'responsibility' => $row['responsibility'],
                    'waiting_on' => $row['waiting_on'],
                    // The System Category (P54) — already pinned and validated by
                    // WorkflowStatusPayload::normalize(), so this only stores it.
                    'system_category' => $row['system_category'],
                    'is_active' => $row['is_active'],
                    'is_default' => $row['is_default'],
                    'position' => $row['position'],
                    'default_assignees' => $assignees,
                ];

                /*
                 * An id the client sent is only honoured if it belongs to THIS Space.
                 *
                 * Otherwise a hand-rolled payload could carry another Space's status id and
                 * have this method rewrite it — the same reason every nested route here checks
                 * the parent as well as the child.
                 */
                $status = $row['id'] !== null ? $existing->get($row['id']) : null;

                if ($status === null) {
                    $status = new HelpCenterStatus([
                        'tenant_id' => $space->tenant_id,
                        'help_center_space_id' => $space->id,
                        // A row created here is never a system row: the two system statuses
                        // exist from setup and cannot be added, only edited.
                        'system_key' => null,
                    ]);
                }

                $status->forceFill($attributes)->save();
                $kept[] = $status->id;
            }

            /*
             * Whatever the editor removed — except the system rows, which it cannot remove and
             * which are protected here too (HC-D14). A payload that dropped Open would already
             * have been refused by validation; this is the second door onto the same rule,
             * closed because "Open and Closed always exist" is a fact about the product.
             */
            $space->statuses()
                ->whereNotIn('id', $kept)
                ->whereNull('system_key')
                ->delete();
        });
    }

    /**
     * Who may be a default assignee in this Space.
     *
     * A member list rather than the whole workspace: assigning to somebody who is not on the
     * Space is assigning to somebody who cannot see the Request.
     *
     * @return array<int, int>
     */
    private function memberIds(HelpCenterSpace $space): array
    {
        return HelpCenterSpaceMember::query()
            ->where('help_center_space_id', $space->id)
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
