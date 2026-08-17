<?php

namespace Tests\Feature\Project;

use App\Models\Project;
use App\Models\ProjectItemLabel;
use App\Models\ProjectItemState;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\Workspace;
use App\Services\ProjectItemStateProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Work item filters (docs/features/filters.md).
 *
 * AND between categories, OR within one — and every one of them narrows what the policy already
 * allowed rather than reaching past it.
 */
class WorkItemFilterTest extends ProjectTestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    private Project $project;

    private function setUpProject(): void
    {
        [$this->owner, $this->workspace] = $this->owner();
        tenancy()->initialize($this->workspace);

        $this->project = $this->makeProject($this->owner, $this->workspace);
        // States are seeded per project on first use of the Work Items screen, so a test that
        // reaches for one has to ask for them first.
        app(ProjectItemStateProvisioner::class)->for($this->project);
    }

    private function state(string $name): ProjectItemState
    {
        return ProjectItemState::query()
            ->where('project_id', $this->project->id)
            ->where('name', $name)
            ->firstOr(fn () => ProjectItemState::create([
                'tenant_id' => $this->workspace->id,
                'project_id' => $this->project->id,
                'name' => $name, 'group' => 'started', 'color' => '#888', 'position' => 9,
            ]));
    }

    private function item(array $overrides = []): WorkItem
    {
        static $n = 0;
        $n++;

        return WorkItem::create(array_merge([
            'tenant_id' => $this->workspace->id,
            'project_id' => $this->project->id,
            'title' => 'Item '.$n,
            'state_id' => $this->state('Backlog')->id,
            'created_by' => $this->owner->id,
        ], $overrides));
    }

    /** @return array<int, string> the titles the screen would list */
    private function listed(string $query = ''): array
    {
        $url = "/projects/{$this->project->id}/work-items".($query ? '?'.$query : '');

        return collect($this->actingAs($this->owner)->get($url)->assertOk()->viewData('bootstrap')['items'])
            ->pluck('title')
            ->sort()
            ->values()
            ->all();
    }

    // ---- the engine -------------------------------------------------------------------------

    public function test_no_filter_lists_everything(): void
    {
        $this->setUpProject();
        $this->item(['title' => 'Alpha']);
        $this->item(['title' => 'Beta']);

        $this->assertSame(['Alpha', 'Beta'], $this->listed());
    }

    public function test_values_inside_one_category_are_ored(): void
    {
        $this->setUpProject();
        $todo = $this->state('To Do');
        $doing = $this->state('In Progress');

        $this->item(['title' => 'Alpha', 'state_id' => $todo->id]);
        $this->item(['title' => 'Beta', 'state_id' => $doing->id]);
        $this->item(['title' => 'Gamma']);

        $this->assertSame(['Alpha', 'Beta'], $this->listed("status={$todo->id},{$doing->id}"));
    }

    public function test_different_categories_are_anded(): void
    {
        $this->setUpProject();
        $todo = $this->state('To Do');

        $this->item(['title' => 'Both', 'state_id' => $todo->id, 'priority' => 'high']);
        $this->item(['title' => 'Status only', 'state_id' => $todo->id, 'priority' => 'low']);
        $this->item(['title' => 'Priority only', 'priority' => 'high']);

        // Status = To Do AND priority = High — only the item satisfying both.
        $this->assertSame(['Both'], $this->listed("status={$todo->id}&priority=high"));
    }

    public function test_an_empty_category_is_not_a_filter(): void
    {
        $this->setUpProject();
        $this->item(['title' => 'Alpha']);

        // `?status=` is the unfiltered list, not "items whose status is nothing" (F-5).
        $this->assertSame(['Alpha'], $this->listed('status='));
    }

    public function test_unknown_values_are_dropped_rather_than_refused(): void
    {
        $this->setUpProject();
        $todo = $this->state('To Do');
        $this->item(['title' => 'Alpha', 'state_id' => $todo->id]);
        $this->item(['title' => 'Beta']);

        // A stale bookmark shows a list, not an error page (F-D8). The valid half still applies.
        $this->assertSame(['Alpha'], $this->listed("status={$todo->id},999999"));
        // And a category with nothing valid left in it stops filtering entirely.
        $this->assertSame(['Alpha', 'Beta'], $this->listed('status=999999'));
    }

    public function test_a_state_from_another_project_is_dropped(): void
    {
        $this->setUpProject();
        $this->item(['title' => 'Alpha']);

        $other = $this->makeProject($this->owner, $this->workspace, [
            'name' => 'Other', 'identifier' => 'oth',
        ]);
        tenancy()->initialize($this->workspace);
        app(ProjectItemStateProvisioner::class)->for($other);

        $foreign = ProjectItemState::query()->where('project_id', $other->id)->firstOrFail();

        // F-8 — a filter is not a way to ask whether something exists elsewhere.
        $this->assertSame(['Alpha'], $this->listed("status={$foreign->id}"));
    }

    // ---- priority ---------------------------------------------------------------------------

    public function test_none_is_an_ordinary_priority_value(): void
    {
        $this->setUpProject();
        $this->item(['title' => 'Urgent one', 'priority' => 'urgent']);
        // NOT NULL, defaulting to `none` — the absence of a priority is spelled out in this
        // schema rather than left as a null, so it filters like any other value.
        $this->item(['title' => 'Unset one']);
        $this->item(['title' => 'Low one', 'priority' => 'low']);

        $this->assertSame(['Unset one', 'Urgent one'], $this->listed('priority=urgent,none'));
        $this->assertSame(['Unset one'], $this->listed('priority=none'));
    }

    // ---- members ----------------------------------------------------------------------------

    public function test_members_and_unassigned_live_in_one_category(): void
    {
        $this->setUpProject();
        $sarah = $this->member($this->workspace, 'member', 'sarah@example.com');
        tenancy()->initialize($this->workspace);

        $hers = $this->item(['title' => 'Hers']);
        $hers->assignees()->attach($sarah->id);

        $mine = $this->item(['title' => 'Mine']);
        $mine->assignees()->attach($this->owner->id);

        $this->item(['title' => 'Nobody’s']);

        $this->assertSame(['Hers'], $this->listed("members={$sarah->id}"));
        $this->assertSame(['Nobody’s'], $this->listed('members=unassigned'));

        // The case the category exists for: "Sarah or unassigned" is a real question, and it
        // would answer nothing if Unassigned were a category of its own (F-D4).
        $this->assertSame(['Hers', 'Nobody’s'], $this->listed("members={$sarah->id},unassigned"));
    }

    // ---- labels -----------------------------------------------------------------------------

    public function test_items_can_be_filtered_by_label(): void
    {
        $this->setUpProject();
        $this->project->forceFill(['features' => ['labels' => true] + (array) $this->project->features])->save();

        $bug = ProjectItemLabel::create([
            'tenant_id' => $this->workspace->id, 'project_id' => $this->project->id,
            'name' => 'Bug', 'color' => '#ef4444',
        ]);

        $tagged = $this->item(['title' => 'Tagged']);
        $tagged->labels()->attach($bug->id);
        $this->item(['title' => 'Untagged']);

        $this->assertSame(['Tagged'], $this->listed("label={$bug->id}"));
    }

    // ---- what the screen hands the panel ----------------------------------------------------

    public function test_the_screen_carries_its_categories_and_its_active_chips(): void
    {
        $this->setUpProject();
        $todo = $this->state('To Do');
        $this->item(['title' => 'Alpha', 'state_id' => $todo->id, 'priority' => 'high']);

        $bootstrap = $this->actingAs($this->owner)
            ->get("/projects/{$this->project->id}/work-items?status={$todo->id}&priority=high")
            ->assertOk()->viewData('bootstrap');

        $this->assertSame(
            ['status', 'members', 'priority'],
            array_column($bootstrap['filterCategories'], 'key'),
        );

        // One chip per CATEGORY, naming its values — the category is the unit of the AND, so it
        // is the unit of removal (F-D5).
        $chips = collect($bootstrap['filterChips'])->keyBy('key');

        $this->assertSame('To Do', $chips['status']['text']);
        $this->assertSame('High', $chips['priority']['text']);
        $this->assertSame(['status' => [(string) $todo->id], 'priority' => ['high']], $bootstrap['activeFilters']);
    }

    public function test_a_category_with_nothing_to_offer_is_not_offered(): void
    {
        $this->setUpProject();

        // Labels is off for this project, so there is nothing to filter by and a panel entry
        // would be a dead end.
        $bootstrap = $this->actingAs($this->owner)
            ->get("/projects/{$this->project->id}/work-items")->assertOk()->viewData('bootstrap');

        $this->assertNotContains('label', array_column($bootstrap['filterCategories'], 'key'));
    }
}
