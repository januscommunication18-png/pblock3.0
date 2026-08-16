<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Every column named in a `with('relation:col,col')` must actually exist.
 *
 * This cannot be caught by exercising the code, because the suite runs on SQLite and
 * production runs on MySQL — and the two disagree here. Laravel quotes identifiers, and
 * SQLite's "double-quoted string literal" misfeature turns `"emoji"` into the STRING 'emoji'
 * when no such column exists, so the query succeeds and returns nonsense. MySQL rejects it:
 *
 *     SQLSTATE[42S22]: Column not found: 1054 Unknown column 'emoji' in 'field list'
 *
 * That is how a select naming a non-existent column passed every test and then failed the
 * moment someone linked a page. Scanning the source is the only way to see it from here.
 */
class EagerLoadColumnsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Relation name => the table it loads from.
     *
     * An unknown relation fails the test rather than being skipped: a constrained eager load
     * that nobody has checked is precisely the thing this exists to catch, so adding one
     * should mean adding it here too.
     *
     * @var array<string, string>
     */
    private const TABLES = [
        'project' => 'projects',
        'editor' => 'users',
        'creator' => 'users',
        'lead' => 'users',
        'actor' => 'users',
        'parent' => 'work_items',
        'state' => 'project_item_states',
        'cycle' => 'cycles',
        'epic' => 'epics',
        'user' => 'users',
        // Your Work's Activity tab loads the item each entry happened to, for the line's link.
        'workItem' => 'work_items',
        // Capacity reads only the ids off the assignee pivot — it splits an item's hours between
        // people (§22) and never shows their names.
        'assignees' => 'users',
    ];

    public function test_every_constrained_eager_load_names_real_columns(): void
    {
        $checked = 0;

        foreach ($this->sources() as $file) {
            $code = file_get_contents($file);

            // Only inside a with(...) call. Matching the string anywhere swept up unrelated
            // colon-separated literals like an artisan command name.
            preg_match_all('/->with\(([^;]*?)\)/s', $code, $calls);

            $matches = [];
            foreach ($calls[1] ?? [] as $args) {
                preg_match_all("/'([a-zA-Z_]+):([a-zA-Z_,]+)'/", $args, $found, PREG_SET_ORDER);
                $matches = array_merge($matches, $found);
            }

            foreach ($matches as [$whole, $relation, $columns]) {
                $table = self::TABLES[$relation] ?? null;

                $this->assertNotNull(
                    $table,
                    "Unknown relation [{$relation}] in {$file} — add it to ".self::class.'::TABLES.',
                );

                foreach (explode(',', $columns) as $column) {
                    $this->assertTrue(
                        Schema::hasColumn($table, $column),
                        "{$whole} in ".basename($file)." names [{$column}], which is not a column on [{$table}].",
                    );
                    $checked++;
                }
            }
        }

        // A scan that silently found nothing would pass forever.
        $this->assertGreaterThan(0, $checked, 'the scan matched no eager loads — has the syntax changed?');
    }

    /** @return array<int, string> */
    private function sources(): array
    {
        $files = [];

        $walk = function (string $dir) use (&$walk, &$files) {
            foreach (scandir($dir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $path = $dir.'/'.$entry;

                if (is_dir($path)) {
                    $walk($path);
                } elseif (str_ends_with($entry, '.php')) {
                    $files[] = $path;
                }
            }
        };

        $walk(app_path());

        return $files;
    }
}
