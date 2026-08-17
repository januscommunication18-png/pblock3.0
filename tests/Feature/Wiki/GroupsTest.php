<?php

namespace Tests\Feature\Wiki;

use App\Models\User;
use App\Models\WikiCollection;
use App\Models\WikiCollectionGroup;
use App\Models\WikiCollectionMember;
use App\Models\WikiPage;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\WorkspaceCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Groups inside a collection (docs/features/wiki.md) — the Group view's sections.
 *
 * A second way of LOOKING at the same pages, not a second place to keep them. Most of what
 * matters here is that grouping never destroys anything.
 */
class GroupsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    private WikiCollection $collection;

    private function setUpWiki(): void
    {
        $this->owner = User::factory()->create(['full_name' => 'Rohit', 'email' => 'owner@example.com']);

        $this->workspace = app(WorkspaceCreator::class)->create($this->owner, [
            'name' => 'Acme Inc', 'slug' => 'acme-inc', 'company_size' => '2-10',
            'apps' => ['projects', 'wiki'],
        ]);

        $this->owner = $this->owner->fresh();
        tenancy()->initialize($this->workspace);

        $this->collection = WikiCollection::create([
            'tenant_id' => $this->workspace->id, 'name' => 'Help Desk Software',
            'visibility' => 'public', 'created_by' => $this->owner->id, 'position' => 1,
        ]);
    }

    private function page(string $title, ?int $groupId = null): WikiPage
    {
        return WikiPage::create([
            'tenant_id' => $this->workspace->id,
            'wiki_collection_id' => $this->collection->id,
            'title' => $title, 'wiki_group_id' => $groupId,
            'created_by' => $this->owner->id, 'updated_by' => $this->owner->id, 'position' => 1,
        ]);
    }

    private function makeGroup(array $overrides = []): WikiCollectionGroup
    {
        return WikiCollectionGroup::create(array_merge([
            'tenant_id' => $this->workspace->id,
            'wiki_collection_id' => $this->collection->id,
            'name' => 'Getting started',
            'created_by' => $this->owner->id,
            'position' => 1,
        ], $overrides));
    }

    // ---- creating ---------------------------------------------------------------------------

    public function test_a_group_carries_a_name_label_and_both_descriptions(): void
    {
        $this->setUpWiki();

        $this->actingAs($this->owner)
            ->postJson("/wiki/collections/{$this->collection->id}/groups", [
                'name' => 'Getting started',
                'label' => 'Internal',
                'short_description' => 'Read these first.',
                'long_description' => 'Everything a new agent needs in their first week.',
            ])->assertOk();

        $group = WikiCollectionGroup::query()->firstOrFail();

        $this->assertSame('Getting started', $group->name);
        $this->assertSame('Internal', $group->label);
        $this->assertSame('Read these first.', $group->short_description);
        $this->assertStringContainsString('first week', $group->long_description);
    }

    public function test_only_the_name_is_required(): void
    {
        $this->setUpWiki();

        $this->actingAs($this->owner)
            ->postJson("/wiki/collections/{$this->collection->id}/groups", ['name' => 'Reference'])
            ->assertOk();

        $this->actingAs($this->owner)
            ->postJson("/wiki/collections/{$this->collection->id}/groups", ['name' => '  '])
            ->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_groups_are_appended_in_the_order_they_are_made(): void
    {
        $this->setUpWiki();

        foreach (['First', 'Second'] as $name) {
            $this->actingAs($this->owner)
                ->postJson("/wiki/collections/{$this->collection->id}/groups", ['name' => $name])->assertOk();
        }

        $this->assertSame(
            ['First', 'Second'],
            WikiCollectionGroup::query()->orderBy('position')->pluck('name')->all(),
        );
    }

    public function test_a_group_can_be_edited(): void
    {
        $this->setUpWiki();
        $group = $this->makeGroup();

        $this->actingAs($this->owner)
            ->patchJson("/wiki/collections/{$this->collection->id}/groups/{$group->id}", [
                'name' => 'Onboarding', 'label' => 'Week one',
            ])->assertOk();

        $this->assertSame('Onboarding', $group->refresh()->name);
        $this->assertSame('Week one', $group->label);
    }

    // ---- filing pages -----------------------------------------------------------------------

    public function test_a_page_can_be_filed_under_a_group_and_taken_back_out(): void
    {
        $this->setUpWiki();
        $group = $this->makeGroup();
        $page = $this->page('Escalation process');

        $this->actingAs($this->owner)
            ->patchJson("/wiki/collections/{$this->collection->id}/pages/{$page->id}/group", [
                'group_id' => $group->id,
            ])->assertOk();

        $this->assertSame($group->id, $page->refresh()->wiki_group_id);

        $this->actingAs($this->owner)
            ->patchJson("/wiki/collections/{$this->collection->id}/pages/{$page->id}/group", [
                'group_id' => null,
            ])->assertOk();

        $this->assertNull($page->refresh()->wiki_group_id);
    }

    public function test_a_page_cannot_be_filed_under_another_collections_group(): void
    {
        $this->setUpWiki();
        $page = $this->page('Escalation process');

        $other = WikiCollection::create([
            'tenant_id' => $this->workspace->id, 'name' => 'Other',
            'visibility' => 'public', 'created_by' => $this->owner->id, 'position' => 2,
        ]);
        $foreign = WikiCollectionGroup::create([
            'tenant_id' => $this->workspace->id, 'wiki_collection_id' => $other->id,
            'name' => 'Elsewhere', 'created_by' => $this->owner->id, 'position' => 1,
        ]);

        // A page cannot be filed under a section of something it is not in.
        $this->actingAs($this->owner)
            ->patchJson("/wiki/collections/{$this->collection->id}/pages/{$page->id}/group", [
                'group_id' => $foreign->id,
            ])->assertStatus(422);
    }

    // ---- removing ---------------------------------------------------------------------------

    public function test_removing_a_group_leaves_its_pages_alone(): void
    {
        $this->setUpWiki();
        $group = $this->makeGroup();
        $page = $this->page('Escalation process', $group->id);

        $this->actingAs($this->owner)
            ->deleteJson("/wiki/collections/{$this->collection->id}/groups/{$group->id}")
            ->assertOk();

        // Removing a section is a decision about ARRANGEMENT. Taking the pages with it would
        // make an organising action destructive, which is not what anybody means by it.
        $page->refresh();

        $this->assertNotNull($page);
        $this->assertNull($page->wiki_group_id);
        $this->assertSame(0, WikiCollectionGroup::query()->count());
    }

    // ---- who may arrange ----------------------------------------------------------------------

    public function test_a_read_only_invitation_cannot_create_or_move_groups(): void
    {
        $this->setUpWiki();
        $this->collection->forceFill(['visibility' => 'private'])->save();

        $sarah = User::factory()->create(['full_name' => 'Sarah', 'email' => 'sarah@example.com']);
        WorkspaceMembership::create([
            'workspace_id' => $this->workspace->id, 'user_id' => $sarah->id,
            'role' => 'member', 'status' => 'active', 'joined_at' => now(),
        ]);
        $sarah->forceFill(['current_workspace_id' => $this->workspace->id])->save();
        WikiCollectionMember::create([
            'tenant_id' => $this->workspace->id, 'wiki_collection_id' => $this->collection->id,
            'user_id' => $sarah->id, 'permission' => 'read', 'invited_by' => $this->owner->id,
        ]);

        $this->actingAs($sarah->fresh())
            ->postJson("/wiki/collections/{$this->collection->id}/groups", ['name' => 'Mine'])
            ->assertForbidden();
    }

    public function test_the_collection_screen_carries_its_groups(): void
    {
        $this->setUpWiki();
        $group = $this->makeGroup(['label' => 'Internal']);
        $this->page('Escalation process', $group->id);

        $bootstrap = $this->actingAs($this->owner)
            ->get("/wiki/collections/{$this->collection->id}")->assertOk()->viewData('bootstrap');

        $this->assertSame('Getting started', $bootstrap['groups'][0]['name']);
        $this->assertSame('Internal', $bootstrap['groups'][0]['label']);
        // The row knows which group it is in, so one payload draws both views.
        $this->assertSame($group->id, $bootstrap['pages'][0]['group_id']);
    }

    // ---- arranging -----------------------------------------------------------------------------

    public function test_groups_can_be_reordered(): void
    {
        $this->setUpWiki();
        $a = $this->makeGroup(['name' => 'First', 'position' => 1]);
        $b = $this->makeGroup(['name' => 'Second', 'position' => 2]);

        $this->actingAs($this->owner)
            ->patchJson("/wiki/collections/{$this->collection->id}/groups/reorder", [
                'ids' => [$b->id, $a->id],
            ])->assertOk();

        $this->assertSame(['Second', 'First'], WikiCollectionGroup::query()
            ->orderBy('position')->pluck('name')->all());
    }

    public function test_a_group_from_another_collection_is_ignored_when_reordering(): void
    {
        $this->setUpWiki();
        $mine = $this->makeGroup(['name' => 'Mine']);

        $other = WikiCollection::create([
            'tenant_id' => $this->workspace->id, 'name' => 'Other',
            'visibility' => 'public', 'created_by' => $this->owner->id, 'position' => 2,
        ]);
        $theirs = WikiCollectionGroup::create([
            'tenant_id' => $this->workspace->id, 'wiki_collection_id' => $other->id,
            'name' => 'Theirs', 'created_by' => $this->owner->id, 'position' => 1,
        ]);

        $this->actingAs($this->owner)
            ->patchJson("/wiki/collections/{$this->collection->id}/groups/reorder", [
                'ids' => [$theirs->id, $mine->id],
            ])->assertOk();

        // Skipped, not rejected: the payload is the whole order, and one stray id should not
        // discard a legitimate rearrangement of the rest.
        $this->assertSame(1, $theirs->refresh()->position);
        $this->assertSame(0, $mine->refresh()->position);
    }

    public function test_dragging_a_page_between_groups_moves_and_orders_it(): void
    {
        $this->setUpWiki();
        $a = $this->makeGroup(['name' => 'First']);
        $b = $this->makeGroup(['name' => 'Second', 'position' => 2]);

        $one = $this->page('Escalation process', $a->id);
        $two = $this->page('Severity levels', $a->id);

        $this->actingAs($this->owner)
            ->patchJson("/wiki/collections/{$this->collection->id}/pages/reorder", ['nodes' => [
                ['id' => $two->id, 'parent_id' => null, 'position' => 0, 'group_id' => $a->id],
                ['id' => $one->id, 'parent_id' => null, 'position' => 0, 'group_id' => $b->id],
            ]])->assertOk();

        $this->assertSame($a->id, $two->refresh()->wiki_group_id);
        $this->assertSame(0, $two->position);
        $this->assertSame($b->id, $one->refresh()->wiki_group_id);
    }

    public function test_reordering_without_mentioning_groups_leaves_them_alone(): void
    {
        $this->setUpWiki();
        $group = $this->makeGroup();
        $page = $this->page('Escalation process', $group->id);

        // This is the List view's payload — it knows nothing about groups, and a missing key
        // must not read as "ungroup everything it touched".
        $this->actingAs($this->owner)
            ->patchJson("/wiki/collections/{$this->collection->id}/pages/reorder", ['nodes' => [
                ['id' => $page->id, 'parent_id' => null, 'position' => 3],
            ]])->assertOk();

        $this->assertSame($group->id, $page->refresh()->wiki_group_id);
        $this->assertSame(3, $page->position);
    }

    // ---- sub-groups --------------------------------------------------------------------------

    public function test_a_group_can_be_created_inside_another(): void
    {
        $this->setUpWiki();
        $parent = $this->makeGroup();

        $this->actingAs($this->owner)
            ->postJson("/wiki/collections/{$this->collection->id}/groups", [
                'name' => 'Installation', 'parent_id' => $parent->id,
            ])->assertOk();

        $sub = WikiCollectionGroup::query()->where('name', 'Installation')->firstOrFail();

        $this->assertSame($parent->id, $sub->parent_id);
        $this->assertTrue($sub->isSubGroup());
    }

    public function test_nesting_stops_at_one_level(): void
    {
        $this->setUpWiki();
        $parent = $this->makeGroup();
        $sub = $this->makeGroup(['name' => 'Installation', 'parent_id' => $parent->id, 'position' => 1]);

        // Deeper is expressible in the data and unreadable on the page: the Group view draws a
        // card per section, and a card inside a card inside a card is not an outline.
        $this->actingAs($this->owner)
            ->postJson("/wiki/collections/{$this->collection->id}/groups", [
                'name' => 'On macOS', 'parent_id' => $sub->id,
            ])->assertStatus(422);

        $this->assertSame(0, WikiCollectionGroup::query()->where('name', 'On macOS')->count());
    }

    public function test_a_parent_from_another_collection_is_refused(): void
    {
        $this->setUpWiki();

        $other = WikiCollection::create([
            'tenant_id' => $this->workspace->id, 'name' => 'Company Handbook',
            'visibility' => 'public', 'created_by' => $this->owner->id, 'position' => 2,
        ]);
        $stranger = WikiCollectionGroup::create([
            'tenant_id' => $this->workspace->id, 'wiki_collection_id' => $other->id,
            'name' => 'Benefits', 'created_by' => $this->owner->id, 'position' => 1,
        ]);

        $this->actingAs($this->owner)
            ->postJson("/wiki/collections/{$this->collection->id}/groups", [
                'name' => 'Installation', 'parent_id' => $stranger->id,
            ])->assertStatus(422);
    }

    public function test_sub_groups_are_positioned_against_their_siblings_not_the_whole_collection(): void
    {
        $this->setUpWiki();
        $parent = $this->makeGroup();
        $this->makeGroup(['name' => 'Escalation', 'position' => 2]);

        $this->actingAs($this->owner)
            ->postJson("/wiki/collections/{$this->collection->id}/groups", [
                'name' => 'Installation', 'parent_id' => $parent->id,
            ])->assertOk();

        // Numbering it after the top-level sections would push the first sub-group of every
        // section to position 3 and make "first" depend on how many siblings its parent has.
        $this->assertSame(1, WikiCollectionGroup::query()->where('name', 'Installation')->value('position'));
    }

    public function test_removing_a_parent_promotes_its_sub_groups_rather_than_taking_them_with_it(): void
    {
        $this->setUpWiki();
        $parent = $this->makeGroup();
        $sub = $this->makeGroup(['name' => 'Installation', 'parent_id' => $parent->id, 'position' => 1]);
        $page = $this->page('Install the agent', $sub->id);

        $this->actingAs($this->owner)
            ->deleteJson("/wiki/collections/{$this->collection->id}/groups/{$parent->id}")
            ->assertOk()->assertJsonPath('message', 'Group removed. Its pages are now ungrouped and its sub-groups moved up a level.');

        // Removing a heading is one decision; the sections beneath it were separate ones, and
        // the pages inside those are not arrangement at all.
        $this->assertNull($sub->refresh()->parent_id);
        $this->assertSame($sub->id, $page->refresh()->wiki_group_id);
    }

    public function test_reordering_one_level_does_not_renumber_the_other(): void
    {
        $this->setUpWiki();
        $parent = $this->makeGroup();
        $second = $this->makeGroup(['name' => 'Escalation', 'position' => 2]);
        $a = $this->makeGroup(['name' => 'Installation', 'parent_id' => $parent->id, 'position' => 1]);
        $b = $this->makeGroup(['name' => 'First ticket', 'parent_id' => $parent->id, 'position' => 2]);

        $this->actingAs($this->owner)
            ->patchJson("/wiki/collections/{$this->collection->id}/groups/reorder", [
                'ids' => [$b->id, $a->id], 'parent_id' => $parent->id,
            ])->assertOk();

        $this->assertSame([0, 1], [$b->refresh()->position, $a->refresh()->position]);

        // Positions are per level. Renumbering the top-level sections from a drag inside one
        // of them would shuffle the page under the user's cursor.
        $this->assertSame(1, $parent->refresh()->position);
        $this->assertSame(2, $second->refresh()->position);
    }

    public function test_a_sub_group_of_another_level_is_ignored_by_reorder(): void
    {
        $this->setUpWiki();
        $parent = $this->makeGroup();
        $sub = $this->makeGroup(['name' => 'Installation', 'parent_id' => $parent->id, 'position' => 1]);

        // Sent with the top level's payload, it belongs to neither the ids nor that level.
        $this->actingAs($this->owner)
            ->patchJson("/wiki/collections/{$this->collection->id}/groups/reorder", [
                'ids' => [$sub->id, $parent->id], 'parent_id' => null,
            ])->assertOk();

        $this->assertSame(1, $sub->refresh()->position);
        $this->assertSame(0, $parent->refresh()->position);
    }

    // ---- how the reader sees them --------------------------------------------------------------

    public function test_the_reader_draws_sub_groups_inside_their_parent(): void
    {
        $this->setUpWiki();
        $parent = $this->makeGroup();
        $sub = $this->makeGroup(['name' => 'Installation', 'parent_id' => $parent->id, 'position' => 1]);
        $this->page('Install the agent', $sub->id);

        $sections = $this->actingAs($this->owner)
            ->get("/wiki/collections/{$this->collection->id}/preview")
            ->assertOk()
            ->viewData('sections');

        // One outer section, not two side by side: a flat nav would put "Installation" beside
        // "Getting started" and lose the one thing nesting was for.
        $this->assertCount(1, $sections);
        $this->assertSame('Getting started', $sections[0]['name']);
        $this->assertSame('Installation', $sections[0]['children'][0]['name']);
        $this->assertSame('Install the agent', $sections[0]['children'][0]['pages'][0]->title);
    }

    public function test_a_page_inside_a_sub_group_can_still_be_opened_by_link(): void
    {
        $this->setUpWiki();
        $parent = $this->makeGroup();
        $sub = $this->makeGroup(['name' => 'Installation', 'parent_id' => $parent->id, 'position' => 1]);
        $this->page('Escalation process', $parent->id);
        $deep = $this->page('Install the agent', $sub->id);

        $current = $this->actingAs($this->owner)
            ->get("/wiki/collections/{$this->collection->id}/preview?page={$deep->id}")
            ->assertOk()
            ->viewData('current');

        // Flattening only the top level would send this link silently back to the first page
        // in the collection — a link that appears to work and opens the wrong document.
        $this->assertSame($deep->id, $current->id);
    }
}
