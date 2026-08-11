<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Resolve the ids left in assignee/label audit rows into display names
 * (Activity & Audit spec §11.6).
 *
 * Those rows store the id list in `old_value` / `new_value` — the machine record — and the
 * names in `meta`. Until now only the AFTER side was resolved, so History rendered rows like
 * "None → 4": a database key shown to a reader. Both sides are resolved on write now, and
 * this fills in the rows written before that.
 *
 * Names are frozen into the row rather than looked up on read, which is the whole point of an
 * audit trail: renaming a label later must not rewrite what the history says happened. An id
 * that no longer resolves — the user or label has since been deleted — becomes a plain
 * "(removed)" rather than reverting to the number.
 */
return new class extends Migration
{
    public function up(): void
    {
        $users = DB::table('users')->pluck('full_name', 'id');
        $emails = DB::table('users')->pluck('email', 'id');
        $labels = DB::table('project_item_labels')->pluck('name', 'id');

        DB::table('work_item_activity')
            ->whereIn('field', ['assignees', 'labels'])
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($users, $emails, $labels) {
                foreach ($rows as $row) {
                    $meta = json_decode((string) $row->meta, true) ?: [];

                    $resolve = function (?string $value) use ($row, $users, $emails, $labels) {
                        if ($value === null || trim($value) === '') {
                            return [];
                        }

                        return collect(explode(',', $value))
                            ->map(fn ($id) => (int) trim($id))
                            ->filter()
                            ->map(function (int $id) use ($row, $users, $emails, $labels) {
                                if ($row->field === 'labels') {
                                    return $labels[$id] ?? '(removed)';
                                }

                                // A user with no name still has an email to show.
                                return $users[$id] ?: ($emails[$id] ?? null) ?: '(removed)';
                            })
                            ->values()
                            ->all();
                    };

                    // Never overwrite what the recorder already froze in.
                    $meta['old_labels'] ??= $resolve($row->old_value);
                    $meta['new_labels'] ??= $resolve($row->new_value);

                    DB::table('work_item_activity')->where('id', $row->id)
                        ->update(['meta' => json_encode($meta)]);
                }
            });
    }

    public function down(): void
    {
        // Nothing to undo: this only fills in display names that were missing, and dropping
        // them again would put database ids back in front of users.
    }
};
