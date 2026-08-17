<?php

namespace Tests\Feature\Wiki;

use App\Models\Project;
use App\Models\ProjectPage;
use App\Models\User;
use App\Models\WikiCollection;
use App\Models\WikiCollectionMember;
use App\Models\WikiPage;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\ProjectCreator;
use App\Services\WikiReader;
use App\Services\WorkspaceCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Linked Pages (docs/features/wiki-linked-pages.md) — a Project Page shown inside a collection.
 *
 * The page does not move and is never copied: what exists in the Wiki is a row that POINTS at it,
 * so both places render one document and there is nothing to keep in step.
 */
class LinkedPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    private WikiCollection $collection;

    private Project $project;

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
            'tenant_id' => $this->workspace->id, 'name' => 'Product Documentation',
            'visibility' => 'public', 'created_by' => $this->owner->id, 'position' => 1,
        ]);

        $this->project = $this->makeProject('Apollo', 'APL');
    }

    /** ProjectCreator wants the whole form, and it leaves tenancy pointed elsewhere. */
    private function makeProject(string $name, string $identifier): Project
    {
        $project = app(ProjectCreator::class)->create($this->owner, $this->workspace, [
            'name' => $name, 'identifier' => $identifier, 'description' => null,
            'visibility' => Project::VISIBILITY_PUBLIC, 'lead_user_id' => null,
        ]);

        tenancy()->initialize($this->workspace);

        // Pages is OFF on a new project — a project has to switch it on. The picker filters on
        // exactly this, so a project without it has nothing to offer and is not listed.
        $project = Project::find($project->id);
        $project->forceFill(['features' => ['pages' => true] + (array) $project->features])->save();

        return $project->fresh();
    }

    private function projectPage(string $title = 'Product Requirements'): ProjectPage
    {
        return ProjectPage::create([
            'tenant_id' => $this->workspace->id,
            'project_id' => $this->project->id,
            'title' => $title,
            'content' => '<p>The requirements live here.</p>',
            'created_by' => $this->owner->id, 'updated_by' => $this->owner->id,
        ]);
    }

    private function link(?int $pageId = null, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner)
            ->postJson("/wiki/collections/{$this->collection->id}/linked-pages", [
                'project_id' => $this->project->id,
                'page_id' => $pageId ?? $this->projectPage()->id,
            ]);
    }

    // ---- linking ----------------------------------------------------------------------------

    public function test_linking_creates_a_pointer_and_never_a_copy(): void
    {
        $this->setUpWiki();
        $page = $this->projectPage();

        $this->link($page->id)->assertOk();

        $linked = WikiPage::query()->firstOrFail();

        $this->assertTrue($linked->isLinked());
        $this->assertSame($page->id, (int) $linked->source_page_id);
        $this->assertSame(WikiPage::SOURCE_PROJECT_PAGE, $linked->source_type);

        // The content is NOT copied — the columns are empty and the values resolve through the
        // pointer. Storing them would be the duplication this feature exists to avoid.
        $this->assertNull($linked->getRawOriginal('title'));
        $this->assertNull($linked->getRawOriginal('content'));
        $this->assertSame('Product Requirements', $linked->title);
        $this->assertStringContainsString('requirements live here', $linked->content);
    }

    public function test_the_project_page_is_untouched(): void
    {
        $this->setUpWiki();
        $page = $this->projectPage();

        $this->link($page->id)->assertOk();

        $page->refresh();

        // It stays under Project → Pages, with its project, its id and its owner unchanged.
        $this->assertSame($this->project->id, (int) $page->project_id);
        $this->assertNull($page->archived_at);
        $this->assertDatabaseHas('project_pages', ['id' => $page->id, 'title' => 'Product Requirements']);
    }

    public function test_a_rename_in_the_project_shows_in_the_wiki(): void
    {
        $this->setUpWiki();
        $page = $this->projectPage();
        $this->link($page->id)->assertOk();

        $page->forceFill(['title' => 'Product Requirements v2'])->save();

        // Nothing to synchronise, because there is only one document.
        $this->assertSame('Product Requirements v2', WikiPage::query()->firstOrFail()->title);
    }

    public function test_a_linked_page_appears_in_the_collection_and_the_reader(): void
    {
        $this->setUpWiki();
        $this->link()->assertOk();

        $bootstrap = $this->actingAs($this->owner)
            ->get("/wiki/collections/{$this->collection->id}")->assertOk()->viewData('bootstrap');

        $this->assertSame('Product Requirements', $bootstrap['pages'][0]['title']);
        $this->assertTrue($bootstrap['pages'][0]['linked']);

        // And in the reading layout, through the same query every other page uses.
        $sections = app(WikiReader::class)->sections($this->collection->fresh());

        $this->assertSame('Product Requirements', $sections[0]['pages'][0]->title);
    }

    public function test_the_same_page_cannot_be_linked_to_one_collection_twice(): void
    {
        $this->setUpWiki();
        $page = $this->projectPage();

        $this->link($page->id)->assertOk();
        $this->link($page->id)->assertStatus(422);

        $this->assertSame(1, WikiPage::query()->count());
    }

    public function test_the_same_page_may_be_linked_to_two_collections(): void
    {
        $this->setUpWiki();
        $page = $this->projectPage();

        $other = WikiCollection::create([
            'tenant_id' => $this->workspace->id, 'name' => 'Engineering',
            'visibility' => 'public', 'created_by' => $this->owner->id, 'position' => 2,
        ]);

        $this->link($page->id)->assertOk();

        $this->actingAs($this->owner)
            ->postJson("/wiki/collections/{$other->id}/linked-pages", [
                'project_id' => $this->project->id, 'page_id' => $page->id,
            ])->assertOk();

        $this->assertSame(2, WikiPage::query()->count());
    }

    public function test_a_linked_page_is_appended_and_does_not_displace_the_order(): void
    {
        $this->setUpWiki();

        $first = WikiPage::create([
            'tenant_id' => $this->workspace->id,
            'wiki_collection_id' => $this->collection->id,
            'title' => 'Escalation process',
            'created_by' => $this->owner->id, 'updated_by' => $this->owner->id, 'position' => 1,
        ]);

        $this->link()->assertOk();

        $titles = WikiPage::query()->orderBy('position')->get()->pluck('title')->all();

        $this->assertSame(['Escalation process', 'Product Requirements'], $titles);
        $this->assertSame($first->id, WikiPage::query()->orderBy('position')->first()->id);
    }

    // ---- the pickers ------------------------------------------------------------------------

    public function test_the_project_picker_offers_only_projects_you_can_open(): void
    {
        $this->setUpWiki();

        $options = $this->actingAs($this->owner)
            ->getJson("/wiki/collections/{$this->collection->id}/linkable/projects")
            ->assertOk()->json('options');

        $this->assertSame(['Apollo'], array_column($options, 'label'));
    }

    public function test_the_page_picker_drops_what_is_already_linked(): void
    {
        $this->setUpWiki();
        $one = $this->projectPage('Product Requirements');
        $this->projectPage('Release Notes');

        $this->link($one->id)->assertOk();

        $options = $this->actingAs($this->owner)
            ->getJson("/wiki/collections/{$this->collection->id}/linkable/pages?project={$this->project->id}")
            ->assertOk()->json('options');

        // Offered and then refused is a worse experience than never offered (§12).
        $this->assertSame(['Release Notes'], array_column($options, 'label'));
    }

    public function test_the_page_picker_says_nothing_about_a_project_you_cannot_open(): void
    {
        $this->setUpWiki();
        $this->projectPage();

        $stranger = User::factory()->create(['email' => 'stranger@example.com']);
        WorkspaceMembership::create([
            'workspace_id' => $this->workspace->id, 'user_id' => $stranger->id,
            'role' => 'member', 'status' => 'active', 'joined_at' => now(),
        ]);
        $stranger->forceFill(['current_workspace_id' => $this->workspace->id])->save();

        WikiCollectionMember::create([
            'tenant_id' => $this->workspace->id, 'wiki_collection_id' => $this->collection->id,
            'user_id' => $stranger->id, 'permission' => 'edit',
        ]);

        // An empty list, not a 403: whether that project exists is not this endpoint's to say.
        $this->actingAs($stranger->fresh())
            ->getJson("/wiki/collections/{$this->collection->id}/linkable/pages?project={$this->project->id}")
            ->assertOk()
            ->assertJsonPath('options', []);
    }

    // ---- refusals ---------------------------------------------------------------------------

    public function test_a_page_from_another_project_is_refused(): void
    {
        $this->setUpWiki();

        $other = $this->makeProject('Gemini', 'GEM');

        $stray = ProjectPage::create([
            'tenant_id' => $this->workspace->id, 'project_id' => $other->id,
            'title' => 'Somebody else’s page',
            'created_by' => $this->owner->id, 'updated_by' => $this->owner->id,
        ]);

        // The project and the page are both named in the request, and they have to agree.
        $this->link($stray->id)->assertStatus(422);
        $this->assertSame(0, WikiPage::query()->count());
    }

    public function test_another_workspaces_project_is_refused(): void
    {
        $this->setUpWiki();

        $stranger = User::factory()->create(['email' => 'other@example.com']);
        $otherWorkspace = app(WorkspaceCreator::class)->create($stranger, [
            'name' => 'Other Ltd', 'slug' => 'other-ltd', 'company_size' => '2-10',
            'apps' => ['projects', 'wiki'],
        ]);

        $foreign = app(ProjectCreator::class)->create($stranger->fresh(), $otherWorkspace, [
            'name' => 'Foreign', 'identifier' => 'FGN', 'description' => null,
            'visibility' => Project::VISIBILITY_PUBLIC, 'lead_user_id' => null,
        ]);

        tenancy()->initialize($this->workspace);

        // §8 — rejected, not silently empty.
        $this->actingAs($this->owner)
            ->postJson("/wiki/collections/{$this->collection->id}/linked-pages", [
                'project_id' => $foreign->id, 'page_id' => 1,
            ])->assertStatus(422);
    }

    public function test_a_read_only_member_cannot_link_a_page_in(): void
    {
        $this->setUpWiki();
        $page = $this->projectPage();

        $sarah = User::factory()->create(['email' => 'sarah@example.com']);
        WorkspaceMembership::create([
            'workspace_id' => $this->workspace->id, 'user_id' => $sarah->id,
            'role' => 'member', 'status' => 'active', 'joined_at' => now(),
        ]);
        $sarah->forceFill(['current_workspace_id' => $this->workspace->id])->save();

        WikiCollectionMember::create([
            'tenant_id' => $this->workspace->id, 'wiki_collection_id' => $this->collection->id,
            'user_id' => $sarah->id, 'permission' => 'read',
        ]);

        // Whoever may add a page may link one; reading is not enough.
        $this->link($page->id, as: $sarah->fresh())->assertForbidden();
    }

    public function test_a_deleted_source_leaves_a_row_that_still_renders(): void
    {
        $this->setUpWiki();
        $page = $this->projectPage();
        $this->link($page->id)->assertOk();

        $page->delete();

        $linked = WikiPage::query()->withSource()->firstOrFail();

        // A page nobody can read is better than a screen that will not draw.
        $this->assertTrue($linked->sourceIsMissing());
        $this->assertSame('Unavailable page', $linked->title);

        $this->actingAs($this->owner)
            ->get("/wiki/collections/{$this->collection->id}")->assertOk();
    }
}
