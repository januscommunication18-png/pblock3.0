<?php

namespace Tests\Feature\Project;

use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\ProjectView;
use App\Models\ProjectViewColumn;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Views — configuration, feature mapping and the feature gate.
 *
 * Source: ProjectBlock 3.0 — View Module Requirements; §27's test list and §29's acceptance
 * criteria. Inline editing and its permissions live in ProjectViewEditingTest.
 */
class ProjectViewTest extends ProjectTestCase
{
    use RefreshDatabase;

    // ================= §4: the feature gate =================

    public function test_the_tab_appears_only_when_views_is_on(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI']);

        // Off by default and never used, so the tab is gone entirely — Feature Disable §5's
        // clean UI for a project that never touched the feature.
        $tabs = $this->actingAs($owner)->get(route('projects.work-items', $project))
            ->assertOk()->viewData('tabs');
        $this->assertNotContains('views', array_column($tabs, 'key'));

        $this->enable($owner, $project, 'views');

        $tabs = $this->actingAs($owner)->get(route('projects.views', $project))
            ->assertOk()->viewData('tabs');
        $this->assertContains('views', array_column($tabs, 'key'));
    }

    public function test_switching_views_off_keeps_every_stored_view_and_its_columns(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->ready($owner, $ws);
        $view = $this->makeView($owner, $project, 'Sprint planning');

        $before = $this->columnKeys($ws, $view['id']);

        $this->disable($owner, $project, 'views');

        // §4.2: existing Views remain stored, and the tab stays because there is history to
        // reach — it opens read-only rather than vanishing.
        $tabs = $this->actingAs($owner)->get(route('projects.work-items', $project))
            ->assertOk()->viewData('tabs');
        $this->assertContains('views', array_column($tabs, 'key'));

        $this->assertSame($before, $this->columnKeys($ws, $view['id']));

        // Reading is still allowed — that is what "remain stored" is for — but every write is
        // refused, not merely hidden.
        $this->actingAs($owner)->get(route('projects.views.show', ['project' => $project->id, 'view' => $view['id']]))
            ->assertOk();

        $this->actingAs($owner)->postJson(route('projects.views.store', $project), [
            'name' => 'Another', 'visibility' => 'private',
        ])->assertForbidden();

        $this->actingAs($owner)->patchJson(route('projects.views.update', [
            'project' => $project->id, 'view' => $view['id'],
        ]), ['name' => 'Renamed'])->assertForbidden();

        // …and re-enabling restores the configuration untouched (§4.2, AC-17).
        $this->enable($owner, $project, 'views');
        $this->assertSame($before, $this->columnKeys($ws, $view['id']));
    }

    // ================= §6: create, and the default columns =================

    public function test_a_new_view_arrives_with_the_default_columns(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->ready($owner, $ws);

        $view = $this->makeView($owner, $project, 'All work items');

        $columns = $this->openColumns($owner, $project, $view['id']);

        // §6.4's fixed three, in order, pinned.
        $this->assertSame(
            ['work_item.identifier', 'work_item.title', 'work_item.state'],
            collect($columns)->where('position', 'fixed')->pluck('key')->all(),
        );

        // The scroll set. Epic, Module, Cycle and Estimate are off on this project so their
        // columns were never seeded — a new View opening onto greyed-out unavailable columns
        // would be a poor introduction to a feature whose point is showing you your data.
        // Labels IS there, because Labels is the one optional feature that defaults to ON.
        $this->assertSame(
            ['work_item.priority', 'member.assignees', 'work_item.start_date', 'work_item.due_date', 'label.labels'],
            collect($columns)->where('position', 'scroll')->pluck('key')->all(),
        );
    }

    public function test_the_default_columns_include_only_the_features_the_project_has_on(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->ready($owner, $ws);
        $this->enable($owner, $project, 'epics');
        $this->enable($owner, $project, 'labels');

        $columns = collect($this->openColumns($owner, $project, $this->makeView($owner, $project)['id']));

        $this->assertContains('epic.title', $columns->pluck('key')->all());
        $this->assertContains('label.labels', $columns->pluck('key')->all());
        // Cycles and Modules are off, so their columns were never seeded.
        $this->assertNotContains('cycle.name', $columns->pluck('key')->all());
        $this->assertNotContains('module.title', $columns->pluck('key')->all());
    }

    public function test_a_view_can_only_be_created_with_a_visibility_the_project_allows(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->ready($owner, $ws);

        $this->actingAs($owner)->postJson(route('projects.views.store', $project), [
            'name' => 'Shared', 'visibility' => 'project',
        ])->assertStatus(201);

        // §4.2 lets a project switch project-wide Views off; the server decides, not the client.
        $this->disable($owner, $project, 'view_project');

        $this->actingAs($owner)->postJson(route('projects.views.store', $project), [
            'name' => 'Shared again', 'visibility' => 'project',
        ])->assertStatus(422)->assertJsonValidationErrors('visibility');
    }

    /**
     * Turning Views off and on again must leave a view creatable.
     *
     * `view_project` and `view_private` default ON — they are permissions Views grants, not
     * extras a user opts into. The dependent cascade used to force EVERY dependent to false
     * when its prerequisite was switched off, so a round trip through the toggle left Views
     * enabled with neither visibility allowed: the create dialog offered nothing and the
     * server refused every value it was sent. The feature was on and unusable.
     */
    public function test_toggling_views_off_and_on_leaves_both_visibilities_allowed(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->ready($owner, $ws);

        $this->disable($owner, $project, 'views');
        $this->enable($owner, $project, 'views');

        $bootstrap = $this->actingAs($owner)->get(route('projects.views', $project))
            ->assertOk()->viewData('bootstrap');

        $this->assertSame(['private', 'project'], array_column($bootstrap['visibilities'], 'key'));

        // …and creating one actually works, which is the thing the user hits.
        $this->actingAs($owner)->postJson(route('projects.views.store', $project), [
            'name' => 'After a round trip', 'visibility' => 'project',
        ])->assertStatus(201);
    }

    public function test_an_opt_in_sub_feature_is_still_cleared_when_its_prerequisite_goes_off(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI']);

        $this->enable($owner, $project, 'cycles');
        $this->enable($owner, $project, 'parallel_cycles');

        $this->disable($owner, $project, 'cycles');
        $this->enable($owner, $project, 'cycles');

        // The other half of the rule: parallel cycles defaults OFF, so it is an extra the user
        // opted into and turning it back on stays a deliberate act.
        $this->assertFalse($project->fresh()->featureEnabled('parallel_cycles'));
    }

    // ================= §5: listing, visibility, lifecycle =================

    public function test_a_private_view_is_invisible_to_everyone_but_its_owner(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->ready($owner, $ws);
        $other = $this->projectMember($ws, $project, 'admin', 'admin@example.com');

        $mine = $this->makeView($owner, $project, 'My private view', 'private');
        $shared = $this->makeView($owner, $project, 'Team view', 'project');

        $listed = collect($this->actingAs($other)->get(route('projects.views', $project))
            ->assertOk()->viewData('bootstrap')['views'])->pluck('name')->all();

        $this->assertSame(['Team view'], $listed);

        // Not merely hidden from the list: opening it 404s, so the response never confirms
        // that someone else's private View exists. A workspace admin is no exception —
        // private means private, and §14.4's management powers are about deleting, not reading.
        $this->actingAs($other)->get(route('projects.views.show', [
            'project' => $project->id, 'view' => $mine['id'],
        ]))->assertNotFound();

        $this->actingAs($other)->get(route('projects.views.show', [
            'project' => $project->id, 'view' => $shared['id'],
        ]))->assertOk();
    }

    public function test_a_duplicate_copies_every_column_and_belongs_to_whoever_made_it(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->ready($owner, $ws);
        $member = $this->projectMember($ws, $project, 'admin', 'admin@example.com');

        $view = $this->makeView($owner, $project, 'Team view', 'project');
        $before = $this->columnKeys($ws, $view['id']);

        $copy = $this->actingAs($member)->postJson(route('projects.views.duplicate', [
            'project' => $project->id, 'view' => $view['id'],
        ]))->assertStatus(201)->json('view');

        $this->assertSame('Team view (copy)', $copy['name']);
        // Sharing is an act. A duplicate that silently appeared in everyone's list because the
        // original was shared would be a surprise.
        $this->assertSame('private', $copy['visibility']);
        $this->assertTrue($copy['is_owner']);
        $this->assertSame($before, $this->columnKeys($ws, $copy['id']));
    }

    public function test_renaming_and_deleting_follow_the_view_permission(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->ready($owner, $ws);
        $contributor = $this->projectMember($ws, $project, 'contributor', 'member@example.com');

        $view = $this->makeView($owner, $project, 'Team view', 'project');
        $url = ['project' => $project->id, 'view' => $view['id']];

        // §14.2: a contributor may edit the DATA in someone else's shared View without being
        // able to rearrange or rename it for everybody.
        $this->actingAs($contributor)->patchJson(route('projects.views.update', $url), ['name' => 'Mine now'])
            ->assertForbidden();

        $this->actingAs($owner)->patchJson(route('projects.views.update', $url), ['name' => 'Renamed'])
            ->assertOk();

        $this->actingAs($contributor)->deleteJson(route('projects.views.destroy', $url))->assertForbidden();
        $this->actingAs($owner)->deleteJson(route('projects.views.destroy', $url))->assertOk();

        $this->assertSame(0, $ws->run(fn () => ProjectView::query()->count()));
    }

    public function test_favorite_toggles_per_user(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->ready($owner, $ws);
        $other = $this->projectMember($ws, $project, 'admin', 'admin@example.com');
        $view = $this->makeView($owner, $project, 'Team view', 'project');
        $url = ['project' => $project->id, 'view' => $view['id']];

        $this->assertTrue($this->actingAs($owner)->postJson(route('projects.views.favorite', $url))->json('favorite'));

        // Per user, which is the whole reason it is not a column on the View.
        $this->assertTrue($this->listed($owner, $project, $view['id'])['favorite']);
        $this->assertFalse($this->listed($other, $project, $view['id'])['favorite']);

        $this->assertFalse($this->actingAs($owner)->postJson(route('projects.views.favorite', $url))->json('favorite'));
    }

    /**
     * The grid on its own page: same data, same permissions, no application chrome.
     *
     * "External" is about chrome, not access — the route sits behind the same auth and the
     * same policy as the ordinary View. Anonymous access is a published link with a token,
     * which is Slice 3.
     */
    public function test_the_external_page_renders_the_view_without_the_app_chrome(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->ready($owner, $ws);
        $other = $this->projectMember($ws, $project, 'admin', 'admin@example.com');
        $view = $this->makeView($owner, $project, 'Meeting', 'project');

        $url = route('projects.views.external', ['project' => $project->id, 'view' => $view['id']]);
        $response = $this->actingAs($owner)->get($url)->assertOk();

        $bootstrap = $response->viewData('bootstrap');
        $this->assertTrue($bootstrap['external']);
        $this->assertNotEmpty($bootstrap['columns'], 'the grid still needs its columns');

        // No sidebar and no project tab bar — the markers those partials always render.
        $response->assertDontSee('id="sidebar"', false);
        $response->assertDontSee('data-sidebar-expand', false);

        // The policy is unchanged: a private view is still nobody else's business.
        $private = $this->makeView($owner, $project, 'Mine', 'private');
        $this->actingAs($other)->get(route('projects.views.external', [
            'project' => $project->id, 'view' => $private['id'],
        ]))->assertNotFound();
    }

    // ================= §8, §9: columns =================

    public function test_columns_can_be_added_removed_and_dragged_between_the_two_cards(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->ready($owner, $ws);
        $view = $this->makeView($owner, $project);
        $url = ['project' => $project->id, 'view' => $view['id']];

        $columns = $this->actingAs($owner)->postJson(route('projects.views.columns.store', $url), [
            'key' => 'work_item.created_at',
        ])->assertOk()->json('columns');

        $this->assertContains('work_item.created_at', collect($columns)->pluck('key')->all());

        // §8.3: a column can exist only once in a View.
        $this->actingAs($owner)->postJson(route('projects.views.columns.store', $url), [
            'key' => 'work_item.created_at',
        ])->assertStatus(422)->assertJsonValidationErrors('key');

        // A drag: priority moves from Scroll to Fixed, and the whole arrangement is sent at
        // once because one move renumbers everything around it in both cards (§8.3).
        $byKey = collect($columns)->keyBy('key');
        $fixed = collect($columns)->where('position', 'fixed')->pluck('id')->all();
        $scroll = collect($columns)->where('position', 'scroll')->pluck('id')->all();
        $priority = (int) $byKey['work_item.priority']['id'];

        $moved = $this->actingAs($owner)->putJson(route('projects.views.columns.order', $url), [
            'fixed' => array_merge($fixed, [$priority]),
            'scroll' => array_values(array_diff($scroll, [$priority])),
        ])->assertOk()->json();

        $this->assertSame('fixed', collect($moved['columns'])->firstWhere('key', 'work_item.priority')['position']);
        // §7.2: the frozen count is derived from the fixed columns, so it moved with it.
        $this->assertSame(count($fixed) + 1, $moved['frozen']);

        // …and it stays there. AC-5: order and position persist after reopening the View.
        $reopened = $this->openColumns($owner, $project, $view['id']);
        $this->assertSame(
            ['work_item.identifier', 'work_item.title', 'work_item.state', 'work_item.priority'],
            collect($reopened)->where('position', 'fixed')->pluck('key')->all(),
        );

        $this->actingAs($owner)->deleteJson(route('projects.views.columns.destroy',
            $url + ['column' => $priority]))->assertOk();

        $this->assertNotContains('work_item.priority', collect($this->openColumns($owner, $project, $view['id']))->pluck('key')->all());
    }

    /**
     * §8.3: Fixed columns render before Scroll columns, whatever order the rows are stored in.
     *
     * This is not cosmetic. §7.2 derives the grid's frozen count from the number of Fixed
     * columns, so the boundary is positional — it pins the first N columns of whatever the
     * server sent. Send Scroll first and the grid pins the wrong columns entirely, which is
     * exactly what a reversed sort produced.
     */
    public function test_fixed_columns_always_come_before_scroll_columns(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->ready($owner, $ws);
        $view = $this->makeView($owner, $project);

        $columns = $this->openColumns($owner, $project, $view['id']);
        $positions = array_column($columns, 'position');

        $firstScroll = array_search('scroll', $positions, true);
        $lastFixed = array_keys($positions, 'fixed', true);
        $lastFixed = end($lastFixed);

        $this->assertNotFalse($firstScroll, 'the view should have scroll columns');
        $this->assertLessThan($firstScroll, $lastFixed, 'every fixed column must precede every scroll column');

        // And the frozen count matches that leading run, which is what makes the boundary
        // land in the right place.
        $frozen = $this->actingAs($owner)->get(route('projects.views.show', [
            'project' => $project->id, 'view' => $view['id'],
        ]))->assertOk()->viewData('bootstrap')['frozen'];

        $this->assertSame($frozen, count(array_filter($positions, fn ($p) => $p === 'fixed')));
        $this->assertSame(array_fill(0, $frozen, 'fixed'), array_slice($positions, 0, $frozen));
    }

    public function test_a_column_width_and_alias_persist(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->ready($owner, $ws);
        $view = $this->makeView($owner, $project);
        $url = ['project' => $project->id, 'view' => $view['id']];

        $title = collect($this->openColumns($owner, $project, $view['id']))->firstWhere('key', 'work_item.title');

        $this->actingAs($owner)->patchJson(route('projects.views.columns.update', $url + ['column' => $title['id']]), [
            'width' => 420, 'display_name' => 'Work item',
        ])->assertOk();

        $reopened = collect($this->openColumns($owner, $project, $view['id']))->firstWhere('key', 'work_item.title');

        $this->assertSame(420, $reopened['width']);
        $this->assertSame('Work item', $reopened['label']);

        // An emptied alias falls back to the catalog's own label rather than storing "".
        $this->actingAs($owner)->patchJson(route('projects.views.columns.update', $url + ['column' => $title['id']]), [
            'display_name' => '  ',
        ])->assertOk();

        $this->assertSame('Title', collect($this->openColumns($owner, $project, $view['id']))
            ->firstWhere('key', 'work_item.title')['label']);
    }

    public function test_a_column_from_another_view_cannot_be_edited_through_this_one(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->ready($owner, $ws);
        $mine = $this->makeView($owner, $project, 'Mine');
        $theirs = $this->makeView($owner, $project, 'Theirs');

        $column = collect($this->openColumns($owner, $project, $theirs['id']))->first();

        // A column id is not a capability: it has to belong to the View in the URL.
        $this->actingAs($owner)->patchJson(route('projects.views.columns.update', [
            'project' => $project->id, 'view' => $mine['id'], 'column' => $column['id'],
        ]), ['width' => 300])->assertForbidden();
    }

    // ================= §9.2, §9.3: feature-aware fields =================

    public function test_fields_are_offered_only_for_features_the_project_has_on(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->ready($owner, $ws);
        $view = $this->makeView($owner, $project);
        $url = ['project' => $project->id, 'view' => $view['id']];

        $groups = collect($this->actingAs($owner)->getJson(route('projects.views.fields', $url))
            ->assertOk()->json('fields'))->keyBy('source');

        // Shown but not selectable, saying why (§9.2) — a selector that simply omitted Epic
        // would leave the user wondering where it went.
        $this->assertFalse($groups['epic']['available']);
        $this->assertStringContainsString('Epics', $groups['epic']['reason']);
        $this->assertTrue($groups['work_item']['available']);

        // …and the server refuses the add, not only the UI.
        $this->actingAs($owner)->postJson(route('projects.views.columns.store', $url), ['key' => 'epic.title'])
            ->assertStatus(422)->assertJsonValidationErrors('key');

        $this->enable($owner, $project, 'epics');

        // §9.2: enabled later, available immediately.
        $groups = collect($this->actingAs($owner)->getJson(route('projects.views.fields', $url))
            ->assertOk()->json('fields'))->keyBy('source');
        $this->assertTrue($groups['epic']['available']);

        $this->actingAs($owner)->postJson(route('projects.views.columns.store', $url), ['key' => 'epic.title'])
            ->assertOk();
    }

    public function test_a_column_survives_its_feature_being_switched_off_and_comes_back(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->ready($owner, $ws);
        $this->enable($owner, $project, 'cycles');
        $view = $this->makeView($owner, $project);

        $before = collect($this->openColumns($owner, $project, $view['id']))->firstWhere('key', 'cycle.name');
        $this->assertNotNull($before, 'the cycle column should have been seeded');
        $this->assertTrue($before['available']);

        $this->disable($owner, $project, 'cycles');

        // §9.3: kept, in place, with its settings — marked unavailable rather than deleted.
        $after = collect($this->openColumns($owner, $project, $view['id']))->firstWhere('key', 'cycle.name');
        $this->assertNotNull($after, 'disabling a feature must not delete the column');
        $this->assertFalse($after['available']);
        $this->assertFalse($after['editable']);
        $this->assertSame($before['position'], $after['position']);
        $this->assertSame($before['sort_order'], $after['sort_order']);
        $this->assertSame($before['width'], $after['width']);
        $this->assertStringContainsString('Cycles', $after['reason']);

        $this->enable($owner, $project, 'cycles');

        $restored = collect($this->openColumns($owner, $project, $view['id']))->firstWhere('key', 'cycle.name');
        $this->assertTrue($restored['available']);
        $this->assertSame($before['sort_order'], $restored['sort_order']);
    }

    /**
     * A row is the same height in a View as it is in the work items list.
     *
     * Both grids render the same rows from the same chip helpers, so a work item that changed
     * height depending on which screen you opened it from would give away that they are two
     * grids — and the point of sharing the renderers is that it should not be visible. Pinned
     * against the list's own row height rather than against the number 44, so moving one moves
     * the other or fails here.
     */
    public function test_the_standard_density_matches_the_work_items_list_row_height(): void
    {
        $list = file_get_contents(public_path('assets/js/projects/work-item-list.js'));

        $this->assertSame(1, preg_match('/rowHeight:\s*(\d+)/', $list, $m),
            'the work items list no longer declares a rowHeight — this test needs rewriting');

        $this->assertSame(
            (int) $m[1],
            (int) config('projects.view_densities.standard.row_height'),
            'Standard density must match the work items list, or the same row looks different in each grid.',
        );
    }

    /**
     * The grid bundle is vendored whole, and reachable.
     *
     * RevoGrid is a Stencil build: the entry point registers the custom elements and then
     * lazily imports the other files in its directory at runtime. Vendoring only the entry
     * gives a grid that loads, defines <revo-grid>, and then fails at the first dynamic
     * import — which presents as an EMPTY ELEMENT rather than an error, so nothing on the
     * server side would notice. This is the same failure the Jodit drop hit.
     */
    public function test_the_views_grid_bundle_is_vendored_with_its_chunks(): void
    {
        $dir = public_path('assets/vendor/revogrid');
        $entry = $dir.'/revo-grid.esm.js';

        $this->assertFileExists($entry, 'the RevoGrid entry point is not vendored');

        // The loader names its lazy bundles in a table; each must be on disk beside it.
        preg_match_all('/"(revo-grid|revogr-[a-z0-9_.-]+)"/', (string) file_get_contents($entry), $found);

        $chunks = collect($found[1] ?? [])->unique()
            ->filter(fn (string $name) => is_file($dir.'/'.$name.'.entry.js'));

        $this->assertGreaterThan(0, $chunks->count(), 'no lazy chunks were found beside the entry point');

        foreach (['revo-grid', 'revogr-data_4', 'revogr-attribution_7'] as $required) {
            $this->assertFileExists($dir.'/'.$required.'.entry.js');
        }
    }

    public function test_the_views_screen_loads_the_grid_and_the_shared_chips(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->ready($owner, $ws);
        $view = $this->makeView($owner, $project);

        $html = $this->actingAs($owner)->get(route('projects.views.show', [
            'project' => $project->id, 'view' => $view['id'],
        ]))->assertOk()->getContent();

        // A module, because Stencil resolves its chunks relative to the script's own URL.
        $this->assertStringContainsString('type="module"', $html);
        $this->assertStringContainsString('assets/vendor/revogrid/revo-grid.esm.js', $html);

        // The cells render through the work item chip helpers, so a status looks the same here
        // as it does in the Work Items list. That is the whole mitigation for running two grid
        // engines, so it is worth a test.
        $this->assertStringContainsString('assets/js/projects/work-item-ui.js', $html);

        // …and Tabulator is NOT dragged along for a screen that no longer uses it.
        $this->assertStringNotContainsString('vendor/tabulator', $html);
    }

    // ================= §24: paged rows =================

    public function test_rows_are_paged_and_searchable_and_sortable(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->ready($owner, $ws);
        $view = $this->makeView($owner, $project);
        $url = ['project' => $project->id, 'view' => $view['id']];

        foreach (['Alpha', 'Bravo', 'Charlie'] as $title) {
            $this->workItem($owner, $project, $title);
        }

        config()->set('projects.view_page_size', 2);

        $first = $this->actingAs($owner)->getJson(route('projects.views.rows', $url))->assertOk()->json();

        $this->assertCount(2, $first['rows']);
        $this->assertTrue($first['has_more']);
        $this->assertSame(3, $first['total']);

        $second = $this->actingAs($owner)->getJson(route('projects.views.rows', $url).'?page=2')
            ->assertOk()->json();

        $this->assertCount(1, $second['rows']);
        $this->assertFalse($second['has_more']);

        // §12.1's search, applied in SQL rather than in the browser.
        $found = $this->actingAs($owner)->getJson(route('projects.views.rows', $url).'?q=Brav')
            ->assertOk()->json('rows');
        $this->assertSame(['Bravo'], array_column($found, 'title'));

        $sorted = $this->actingAs($owner)->getJson(route('projects.views.rows', $url).'?sort=work_item.title&dir=desc&page=1')
            ->assertOk()->json('rows');
        $this->assertSame('Charlie', $sorted[0]['title']);
    }

    public function test_search_treats_wildcards_as_text(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->ready($owner, $ws);
        $view = $this->makeView($owner, $project);

        $this->workItem($owner, $project, 'Alpha');
        $this->workItem($owner, $project, '100% coverage');

        // A user typing % is searching for a per-cent sign, not asking for everything.
        $rows = $this->actingAs($owner)->getJson(route('projects.views.rows', [
            'project' => $project->id, 'view' => $view['id'],
        ]).'?q=100%25')->assertOk()->json('rows');

        $this->assertSame(['100% coverage'], array_column($rows, 'title'));
    }

    // ================= helpers =================

    /** A project with Views switched on and its work item states seeded. */
    private function ready(User $owner, Workspace $ws, string $identifier = 'TESTI'): Project
    {
        $project = $this->makeProject($owner, $ws, ['identifier' => $identifier]);
        $this->enable($owner, $project, 'views');
        // Seeds the project's states, which rows and the Status column read.
        $this->actingAs($owner)->get(route('projects.work-items', $project));

        return $project;
    }

    /** @return array<string, mixed> */
    private function makeView(User $actor, Project $project, string $name = 'All work items', string $visibility = 'private'): array
    {
        return $this->actingAs($actor)->postJson(route('projects.views.store', $project), [
            'name' => $name, 'visibility' => $visibility,
        ])->assertStatus(201)->json('view');
    }

    /** @return array<int, array<string, mixed>> */
    private function openColumns(User $actor, Project $project, int $viewId): array
    {
        return $this->actingAs($actor)->get(route('projects.views.show', [
            'project' => $project->id, 'view' => $viewId,
        ]))->assertOk()->viewData('bootstrap')['columns'];
    }

    /** @return array<string, mixed> */
    private function listed(User $actor, Project $project, int $viewId): array
    {
        return collect($this->actingAs($actor)->get(route('projects.views', $project))
            ->assertOk()->viewData('bootstrap')['views'])->firstWhere('id', $viewId);
    }

    /** Read straight from the database — the point is what SURVIVED, not what was rendered. */
    private function columnKeys(Workspace $ws, int $viewId): array
    {
        return $ws->run(fn () => ProjectViewColumn::query()
            ->where('project_view_id', $viewId)
            ->orderBy('position_type')
            ->orderBy('sort_order')
            ->get()
            ->map(fn (ProjectViewColumn $c) => $c->position_type.':'.$c->key())
            ->all());
    }

    private function enable(User $owner, Project $project, string $feature): void
    {
        $this->actingAs($owner)->postJson(route('projects.settings.features.toggle', $project), [
            'feature' => $feature, 'enabled' => true,
        ])->assertOk();
    }

    private function disable(User $owner, Project $project, string $feature): void
    {
        $this->actingAs($owner)->postJson(route('projects.settings.features.toggle', $project), [
            'feature' => $feature, 'enabled' => false, 'confirm' => true,
        ])->assertOk();
    }

    private function projectMember(Workspace $ws, Project $project, string $role, string $email): User
    {
        $user = $this->member($ws, 'member', $email);

        $this->actingAs($project->creator ?? $user);

        $ws->run(fn () => ProjectMember::create([
            'project_id' => $project->id, 'user_id' => $user->id, 'role' => $role,
        ]));

        return $user->fresh();
    }

    private function workItem(User $actor, Project $project, string $title): int
    {
        return (int) $this->actingAs($actor)
            ->postJson(route('projects.work-items.store', $project), ['title' => $title])
            ->assertStatus(201)->json('item.id');
    }
}
