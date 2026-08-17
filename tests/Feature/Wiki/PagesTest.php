<?php

namespace Tests\Feature\Wiki;

use App\Models\User;
use App\Models\WikiCollection;
use App\Models\WikiCollectionGroup;
use App\Models\WikiCollectionMember;
use App\Models\WikiLabel;
use App\Models\WikiPage;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\WorkspaceCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Wiki pages (docs/features/wiki.md) — the same shape Project Pages already have: name it,
 * land in the editor, autosave the body.
 */
class PagesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    private WikiCollection $collection;

    private function setUpWiki(string $visibility = 'public'): void
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
            'visibility' => $visibility, 'created_by' => $this->owner->id, 'position' => 1,
        ]);
    }

    private function mate(string $email, ?string $permission = null): User
    {
        $user = User::factory()->create(['full_name' => 'Sarah Johnson', 'email' => $email]);

        WorkspaceMembership::create([
            'workspace_id' => $this->workspace->id, 'user_id' => $user->id,
            'role' => 'member', 'status' => 'active', 'joined_at' => now(),
        ]);
        $user->forceFill(['current_workspace_id' => $this->workspace->id])->save();

        if ($permission) {
            WikiCollectionMember::create([
                'tenant_id' => $this->workspace->id, 'wiki_collection_id' => $this->collection->id,
                'user_id' => $user->id, 'permission' => $permission, 'invited_by' => $this->owner->id,
            ]);
        }

        return $user->fresh();
    }

    // ---- create, then open --------------------------------------------------------------

    public function test_a_page_is_created_from_a_name_and_hands_back_where_to_go(): void
    {
        $this->setUpWiki();

        $response = $this->actingAs($this->owner)
            ->postJson("/wiki/collections/{$this->collection->id}/pages", ['title' => 'Escalation process'])
            ->assertOk();

        $page = WikiPage::query()->firstOrFail();

        $this->assertSame('Escalation process', $page->title);
        $this->assertSame($this->collection->id, $page->wiki_collection_id);
        $this->assertSame($this->owner->id, $page->created_by);
        // Creating a document and then having to find it in a list is a step nobody wants.
        $this->assertStringEndsWith("/wiki/collections/{$this->collection->id}/pages/{$page->id}", $response->json('url'));
    }

    public function test_a_page_needs_a_name(): void
    {
        $this->setUpWiki();

        $this->actingAs($this->owner)
            ->postJson("/wiki/collections/{$this->collection->id}/pages", ['title' => ''])
            ->assertStatus(422)->assertJsonValidationErrors('title');
    }

    public function test_new_pages_are_appended_rather_than_jumping_to_the_top(): void
    {
        $this->setUpWiki();

        foreach (['First', 'Second'] as $title) {
            $this->actingAs($this->owner)
                ->postJson("/wiki/collections/{$this->collection->id}/pages", ['title' => $title])->assertOk();
        }

        $this->assertSame(['First', 'Second'], WikiPage::query()->orderBy('position')->pluck('title')->all());
    }

    public function test_the_editor_screen_carries_the_page_and_its_collection(): void
    {
        $this->setUpWiki();
        $page = $this->page();

        $bootstrap = $this->actingAs($this->owner)
            ->get("/wiki/collections/{$this->collection->id}/pages/{$page->id}")
            ->assertOk()->viewData('bootstrap');

        $this->assertSame('Escalation process', $bootstrap['page']['title']);
        $this->assertSame('Help Desk Software', $bootstrap['collection']['name']);
        $this->assertTrue($bootstrap['canEdit']);
    }

    // ---- saving ---------------------------------------------------------------------------

    public function test_the_body_saves(): void
    {
        $this->setUpWiki();
        $page = $this->page();

        $this->actingAs($this->owner)
            ->patchJson("/wiki/collections/{$this->collection->id}/pages/{$page->id}", [
                'title' => 'Escalation process v2',
                'content' => '<p>Ring the on-call engineer.</p>',
            ])->assertOk();

        $page->refresh();

        $this->assertSame('Escalation process v2', $page->title);
        $this->assertStringContainsString('on-call engineer', $page->content);
        $this->assertSame($this->owner->id, $page->updated_by);
    }

    public function test_the_body_is_sanitized_on_the_way_in(): void
    {
        $this->setUpWiki();
        $page = $this->page();

        // The editor is a rich-text field, so its output is user input like any other.
        $this->actingAs($this->owner)
            ->patchJson("/wiki/collections/{$this->collection->id}/pages/{$page->id}", [
                'content' => '<p>Safe</p><script>alert(1)</script>',
            ])->assertOk();

        $this->assertStringNotContainsString('<script', (string) $page->refresh()->content);
    }

    public function test_a_page_reached_through_the_wrong_collection_is_not_found(): void
    {
        $this->setUpWiki();
        $page = $this->page();

        $other = WikiCollection::create([
            'tenant_id' => $this->workspace->id, 'name' => 'Other',
            'visibility' => 'public', 'created_by' => $this->owner->id, 'position' => 2,
        ]);

        $this->actingAs($this->owner)
            ->get("/wiki/collections/{$other->id}/pages/{$page->id}")->assertNotFound();
    }

    // ---- who may write --------------------------------------------------------------------

    public function test_a_read_only_invitation_opens_pages_and_changes_none_of_them(): void
    {
        $this->setUpWiki('private');
        $page = $this->page();
        $sarah = $this->mate('sarah@example.com', 'read');

        $this->actingAs($sarah)
            ->get("/wiki/collections/{$this->collection->id}/pages/{$page->id}")
            ->assertOk()
            ->assertViewHas('bootstrap', fn ($b) => $b['canEdit'] === false);

        $this->actingAs($sarah)
            ->patchJson("/wiki/collections/{$this->collection->id}/pages/{$page->id}", ['title' => 'Hijacked'])
            ->assertForbidden();
    }

    public function test_an_edit_invitation_may_write(): void
    {
        $this->setUpWiki('private');
        $page = $this->page();
        $sarah = $this->mate('sarah@example.com', 'edit');

        $this->actingAs($sarah)
            ->patchJson("/wiki/collections/{$this->collection->id}/pages/{$page->id}", ['title' => 'Updated'])
            ->assertOk();

        $this->assertSame('Updated', $page->refresh()->title);
    }

    public function test_reading_a_public_collection_does_not_grant_writing_it(): void
    {
        $this->setUpWiki();
        $page = $this->page();
        $anyone = $this->mate('anyone@example.com');

        // Visibility answers "who can see this"; it is not an answer to "who can change it".
        $this->actingAs($anyone)
            ->get("/wiki/collections/{$this->collection->id}/pages/{$page->id}")->assertOk();

        $this->actingAs($anyone)
            ->postJson("/wiki/collections/{$this->collection->id}/pages", ['title' => 'Mine now'])
            ->assertForbidden();
    }

    public function test_a_private_collections_pages_are_closed_to_outsiders(): void
    {
        $this->setUpWiki('private');
        $page = $this->page();
        $stranger = $this->mate('stranger@example.com');

        // Access follows the collection, so a page inherits its answer rather than carrying
        // one of its own.
        $this->actingAs($stranger)
            ->get("/wiki/collections/{$this->collection->id}/pages/{$page->id}")->assertForbidden();
    }

    // ---- the collection's page table -----------------------------------------------------

    public function test_the_row_carries_every_column_the_table_shows(): void
    {
        $this->setUpWiki();
        $parent = $this->page();

        $label = WikiLabel::create([
            'tenant_id' => $this->workspace->id, 'name' => 'Runbook', 'color' => '#2563EB', 'position' => 1,
        ]);
        $parent->labels()->sync([$label->id]);

        WikiPage::create([
            'tenant_id' => $this->workspace->id, 'wiki_collection_id' => $this->collection->id,
            'title' => 'Severity levels', 'parent_id' => $parent->id,
            'created_by' => $this->owner->id, 'updated_by' => $this->owner->id, 'position' => 2,
        ]);

        $rows = collect($this->actingAs($this->owner)
            ->get("/wiki/collections/{$this->collection->id}")->assertOk()
            ->viewData('bootstrap')['pages'])->keyBy('title');

        $this->assertSame('Rohit', $rows['Escalation process']['owner']['name']);
        $this->assertSame(1, $rows['Escalation process']['nested']);
        $this->assertSame('Runbook', $rows['Escalation process']['labels'][0]['name']);
        $this->assertNotEmpty($rows['Escalation process']['last_activity']);

        // A nested page names where it sits, so the table reads as a structure.
        $this->assertSame('Escalation process', $rows['Severity levels']['parent_title']);
        $this->assertSame(0, $rows['Severity levels']['nested']);
    }

    public function test_a_page_can_be_renamed_renested_and_labelled(): void
    {
        $this->setUpWiki();
        $parent = $this->page();
        $child = WikiPage::create([
            'tenant_id' => $this->workspace->id, 'wiki_collection_id' => $this->collection->id,
            'title' => 'Severity levels', 'created_by' => $this->owner->id,
            'updated_by' => $this->owner->id, 'position' => 2,
        ]);

        $label = WikiLabel::create([
            'tenant_id' => $this->workspace->id, 'name' => 'Runbook', 'color' => '#2563EB', 'position' => 1,
        ]);

        $this->actingAs($this->owner)
            ->patchJson("/wiki/collections/{$this->collection->id}/pages/{$child->id}/details", [
                'title' => 'Severity matrix',
                'parent_id' => $parent->id,
                'labels' => [$label->id],
            ])->assertOk();

        $child->refresh();

        $this->assertSame('Severity matrix', $child->title);
        $this->assertSame($parent->id, $child->parent_id);
        $this->assertSame(['Runbook'], $child->labels->pluck('name')->all());
    }

    public function test_a_page_cannot_be_nested_under_itself(): void
    {
        $this->setUpWiki();
        $page = $this->page();

        // A page nested under itself disappears from every tree that tries to draw it.
        $this->actingAs($this->owner)
            ->patchJson("/wiki/collections/{$this->collection->id}/pages/{$page->id}/details", [
                'title' => 'Escalation process', 'parent_id' => $page->id,
            ])->assertStatus(422);
    }

    public function test_a_page_cannot_be_nested_under_one_from_another_collection(): void
    {
        $this->setUpWiki();
        $page = $this->page();

        $other = WikiCollection::create([
            'tenant_id' => $this->workspace->id, 'name' => 'Other',
            'visibility' => 'public', 'created_by' => $this->owner->id, 'position' => 2,
        ]);
        $foreign = WikiPage::create([
            'tenant_id' => $this->workspace->id, 'wiki_collection_id' => $other->id,
            'title' => 'Elsewhere', 'created_by' => $this->owner->id,
            'updated_by' => $this->owner->id, 'position' => 1,
        ]);

        $this->actingAs($this->owner)
            ->patchJson("/wiki/collections/{$this->collection->id}/pages/{$page->id}/details", [
                'title' => 'Escalation process', 'parent_id' => $foreign->id,
            ])->assertStatus(422);
    }

    public function test_removing_from_a_collection_archives_rather_than_destroys(): void
    {
        $this->setUpWiki();
        $page = $this->page();

        $this->actingAs($this->owner)
            ->deleteJson("/wiki/collections/{$this->collection->id}/pages/{$page->id}")
            ->assertOk()
            ->assertJsonPath('pages', []);

        // "Archive instead of delete" is the posture of the whole feature — a page removed by
        // mistake is a page somebody wrote.
        $this->assertNotNull($page->refresh()->archived_at);
        $this->assertNotNull(WikiPage::query()->find($page->id));
    }

    public function test_a_read_only_invitation_cannot_edit_or_remove_a_row(): void
    {
        $this->setUpWiki('private');
        $page = $this->page();
        $sarah = $this->mate('sarah@example.com', 'read');

        $this->actingAs($sarah)
            ->patchJson("/wiki/collections/{$this->collection->id}/pages/{$page->id}/details", [
                'title' => 'Hijacked',
            ])->assertForbidden();

        $this->actingAs($sarah)
            ->deleteJson("/wiki/collections/{$this->collection->id}/pages/{$page->id}")
            ->assertForbidden();
    }

    // ---- dragging the tree ------------------------------------------------------------------

    public function test_dragging_renests_and_reorders_in_one_write(): void
    {
        $this->setUpWiki();
        $api = $this->page();
        $overview = $this->makePage('Overview', position: 2);
        $auth = $this->makePage('Authentication', position: 3);

        // One drop can renumber every sibling on both sides of the move, so the whole tree
        // arrives rather than a single delta the server would have to guess the rest from.
        $this->actingAs($this->owner)
            ->patchJson("/wiki/collections/{$this->collection->id}/pages/reorder", ['nodes' => [
                ['id' => $api->id, 'parent_id' => null, 'position' => 0],
                ['id' => $auth->id, 'parent_id' => $api->id, 'position' => 0],
                ['id' => $overview->id, 'parent_id' => $api->id, 'position' => 1],
            ]])->assertOk();

        $this->assertSame($api->id, $auth->refresh()->parent_id);
        $this->assertSame(0, $auth->position);
        $this->assertSame(1, $overview->refresh()->position);
    }

    public function test_a_move_that_would_close_a_loop_is_refused(): void
    {
        $this->setUpWiki();
        $a = $this->page();
        $b = $this->makePage('Child', position: 2);

        // A → B → A cannot be produced by dragging, but the endpoint is reachable without the
        // UI, and a cycle makes every walk of the tree run forever.
        $this->actingAs($this->owner)
            ->patchJson("/wiki/collections/{$this->collection->id}/pages/reorder", ['nodes' => [
                ['id' => $a->id, 'parent_id' => $b->id, 'position' => 0],
                ['id' => $b->id, 'parent_id' => $a->id, 'position' => 0],
            ]])->assertStatus(422);

        $this->assertNull($a->refresh()->parent_id);
        $this->assertNull($b->refresh()->parent_id);
    }

    public function test_a_page_from_another_collection_cannot_be_dragged_in(): void
    {
        $this->setUpWiki();
        $mine = $this->page();

        $other = WikiCollection::create([
            'tenant_id' => $this->workspace->id, 'name' => 'Other',
            'visibility' => 'public', 'created_by' => $this->owner->id, 'position' => 2,
        ]);
        $theirs = WikiPage::create([
            'tenant_id' => $this->workspace->id, 'wiki_collection_id' => $other->id,
            'title' => 'Elsewhere', 'created_by' => $this->owner->id,
            'updated_by' => $this->owner->id, 'position' => 1,
        ]);

        $this->actingAs($this->owner)
            ->patchJson("/wiki/collections/{$this->collection->id}/pages/reorder", ['nodes' => [
                ['id' => $theirs->id, 'parent_id' => $mine->id, 'position' => 0],
            ]])->assertOk();

        // Silently ignored rather than 422: the payload is the whole tree, and one stray id in
        // it should not throw away a legitimate reorder of everything else.
        $this->assertNull($theirs->refresh()->parent_id);
        $this->assertSame($other->id, $theirs->wiki_collection_id);
    }

    public function test_a_read_only_invitation_cannot_rearrange_the_tree(): void
    {
        $this->setUpWiki('private');
        $page = $this->page();
        $sarah = $this->mate('sarah@example.com', 'read');

        $this->actingAs($sarah)
            ->patchJson("/wiki/collections/{$this->collection->id}/pages/reorder", ['nodes' => [
                ['id' => $page->id, 'parent_id' => null, 'position' => 5],
            ]])->assertForbidden();
    }

    // ---- creating one UNDER another ------------------------------------------------------

    public function test_a_page_can_be_created_underneath_another(): void
    {
        $this->setUpWiki();
        $parent = $this->page();

        $this->actingAs($this->owner)
            ->postJson("/wiki/collections/{$this->collection->id}/pages", [
                'title' => 'Severity levels', 'parent_id' => $parent->id,
            ])->assertOk()->assertJsonPath('page.parent_id', $parent->id);

        // The "+" on a row exists so the nesting is decided at the moment the page is named,
        // rather than created loose and dragged into place afterwards.
        $this->assertSame($parent->id, WikiPage::query()->where('title', 'Severity levels')->value('parent_id'));
    }

    public function test_a_sub_page_joins_its_parents_group(): void
    {
        $this->setUpWiki();

        $group = WikiCollectionGroup::create([
            'tenant_id' => $this->workspace->id, 'wiki_collection_id' => $this->collection->id,
            'name' => 'Getting started', 'created_by' => $this->owner->id, 'position' => 1,
        ]);

        $parent = $this->page();
        $parent->forceFill(['wiki_group_id' => $group->id])->save();

        $this->actingAs($this->owner)
            ->postJson("/wiki/collections/{$this->collection->id}/pages", [
                'title' => 'Severity levels', 'parent_id' => $parent->id,
            ])->assertOk();

        // Filing a child away from its own parent would split one section in two, and the
        // Group view would show the parent under a heading its children are missing from.
        $this->assertSame($group->id, WikiPage::query()->where('title', 'Severity levels')->value('wiki_group_id'));
    }

    public function test_a_page_from_another_collection_cannot_be_the_parent(): void
    {
        $this->setUpWiki();

        $other = WikiCollection::create([
            'tenant_id' => $this->workspace->id, 'name' => 'Company Handbook',
            'visibility' => 'public', 'created_by' => $this->owner->id, 'position' => 2,
        ]);
        $stranger = WikiPage::create([
            'tenant_id' => $this->workspace->id, 'wiki_collection_id' => $other->id,
            'title' => 'Expenses', 'created_by' => $this->owner->id,
            'updated_by' => $this->owner->id, 'position' => 1,
        ]);

        // A tree spanning two collections has no rendering: whichever one you opened would
        // show half of it.
        $this->actingAs($this->owner)
            ->postJson("/wiki/collections/{$this->collection->id}/pages", [
                'title' => 'Severity levels', 'parent_id' => $stranger->id,
            ])->assertStatus(422);

        $this->assertSame(0, WikiPage::query()->where('title', 'Severity levels')->count());
    }

    // ---- creating one INSIDE a group ------------------------------------------------------

    public function test_a_page_can_be_created_straight_into_a_group(): void
    {
        $this->setUpWiki();

        $group = WikiCollectionGroup::create([
            'tenant_id' => $this->workspace->id, 'wiki_collection_id' => $this->collection->id,
            'name' => 'Getting started', 'created_by' => $this->owner->id, 'position' => 1,
        ]);

        $this->actingAs($this->owner)
            ->postJson("/wiki/collections/{$this->collection->id}/pages", [
                'title' => 'Severity levels', 'group_id' => $group->id,
            ])->assertOk();

        // Group view is where somebody is thinking in sections. A page added from a section's
        // own header must arrive filed, not at the foot of Ungrouped to be dragged back.
        $this->assertSame($group->id, WikiPage::query()->where('title', 'Severity levels')->value('wiki_group_id'));
    }

    public function test_a_group_from_another_collection_is_refused(): void
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
            ->postJson("/wiki/collections/{$this->collection->id}/pages", [
                'title' => 'Severity levels', 'group_id' => $stranger->id,
            ])->assertStatus(422);

        $this->assertSame(0, WikiPage::query()->where('title', 'Severity levels')->count());
    }

    public function test_a_parent_decides_the_group_even_when_one_is_asked_for(): void
    {
        $this->setUpWiki();

        $groups = collect(['Getting started', 'Escalation'])->map(fn ($name, $i) => WikiCollectionGroup::create([
            'tenant_id' => $this->workspace->id, 'wiki_collection_id' => $this->collection->id,
            'name' => $name, 'created_by' => $this->owner->id, 'position' => $i + 1,
        ]));

        $parent = $this->page();
        $parent->forceFill(['wiki_group_id' => $groups[0]->id])->save();

        $this->actingAs($this->owner)
            ->postJson("/wiki/collections/{$this->collection->id}/pages", [
                'title' => 'Severity levels', 'parent_id' => $parent->id, 'group_id' => $groups[1]->id,
            ])->assertOk();

        // A child in a different section from its own parent would appear under a heading its
        // parent is absent from. Belonging to the parent is the stronger claim.
        $this->assertSame($groups[0]->id, WikiPage::query()->where('title', 'Severity levels')->value('wiki_group_id'));
    }

    private function makePage(string $title, int $position): WikiPage
    {
        return WikiPage::create([
            'tenant_id' => $this->workspace->id,
            'wiki_collection_id' => $this->collection->id,
            'title' => $title,
            'created_by' => $this->owner->id,
            'updated_by' => $this->owner->id,
            'position' => $position,
        ]);
    }

    private function page(): WikiPage
    {
        return WikiPage::create([
            'tenant_id' => $this->workspace->id,
            'wiki_collection_id' => $this->collection->id,
            'title' => 'Escalation process',
            'created_by' => $this->owner->id,
            'updated_by' => $this->owner->id,
            'position' => 1,
        ]);
    }
}
