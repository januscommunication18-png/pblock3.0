<?php

namespace Tests\Feature\Project;

use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\ProjectPage;
use App\Models\ProjectPageVersion;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Project pages.
 * Source: ProjectBlock 3.0 — Project Pages Requirement; §16's acceptance criteria.
 */
class ProjectPageTest extends ProjectTestCase
{
    use RefreshDatabase;

    // ================= AC-01 / AC-02: the tab follows the setting =================

    public function test_the_tab_and_the_page_appear_only_when_pages_is_on(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI']);

        // AC-01: off by default (§3) — no tab, and the screen is not reachable. Unlike Epics
        // and Modules, reading is gated too: §5 restricts direct access to a disabled
        // project's pages rather than keeping them readable for reference.
        $this->actingAs($owner)->get(route('projects.pages', $project))->assertNotFound();

        $this->enablePages($owner, $project);

        // AC-02: the tab appears.
        $tabs = $this->actingAs($owner)->get(route('projects.pages', $project))
            ->assertOk()->viewData('tabs');
        $this->assertContains('pages', array_column($tabs, 'key'));
    }

    public function test_the_setting_applies_to_one_project_only(): void
    {
        [$owner, $ws] = $this->owner();
        $a = $this->makeProject($owner, $ws, ['identifier' => 'AAA', 'name' => 'Project A']);
        $b = $this->makeProject($owner, $ws, ['identifier' => 'BBB', 'name' => 'Project B']);

        $this->enablePages($owner, $a);

        // AC-03/AC-04: enabling in A does not enable in B.
        $this->assertTrue($a->fresh()->featureEnabled('pages'));
        $this->assertFalse($b->fresh()->featureEnabled('pages'));
        $this->actingAs($owner)->get(route('projects.pages', $b))->assertNotFound();
    }

    // ================= AC-05 / AC-06: creating =================

    public function test_a_page_needs_only_a_title_and_belongs_to_its_project(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->enabled($owner, $ws);
        $other = $this->makeProject($owner, $ws, ['identifier' => 'OTHER', 'name' => 'Other']);

        // AC-05: an authorized user can create one. §8: the project is inherited, and a
        // payload naming a different one is ignored rather than honoured.
        $page = $this->actingAs($owner)->postJson(route('projects.pages.store', $project), [
            'title' => '  Project Requirements  ', 'project_id' => $other->id,
        ])->assertStatus(201)->json('page');

        $this->assertSame('Project Requirements', $page['title']);
        // AC-06: it belongs to the project it was created from.
        $this->assertSame($project->id, $ws->run(fn () => ProjectPage::find($page['id'])->project_id));

        // §8: created-by and updated-by are recorded without the client sending them.
        $this->assertSame($owner->id, $page['created_by']['id']);
        $this->assertSame($owner->id, $page['updated_by']['id']);
    }

    public function test_a_blank_title_is_rejected(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->enabled($owner, $ws);

        $this->actingAs($owner)->postJson(route('projects.pages.store', $project), ['title' => '   '])
            ->assertStatus(422)->assertJsonValidationErrors('title');
    }

    // ================= §10/§12: the content =================

    public function test_page_content_is_saved_and_sanitized(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->enabled($owner, $ws);
        $page = $this->page($owner, $project, 'Meeting notes');

        $this->actingAs($owner)->patchJson(route('projects.pages.update', [
            'project' => $project->id, 'page' => $page['id'],
        ]), [
            'content' => '<h2>Decisions</h2><p>We agreed.</p><script>alert(1)</script>',
        ])->assertOk();

        $stored = $ws->run(fn () => ProjectPage::find($page['id'])->content);

        // §10's formatting survives; the script does not — the editor's output is sanitized
        // before it is stored, so what reaches another reader's browser is the cleaned copy.
        $this->assertStringContainsString('<h2>Decisions</h2>', $stored);
        $this->assertStringNotContainsString('<script', $stored);
    }

    public function test_the_editor_url_carries_only_that_pages_content(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->enabled($owner, $ws);
        $one = $this->page($owner, $project, 'One');
        $this->page($owner, $project, 'Two');

        $this->actingAs($owner)->patchJson(route('projects.pages.update', [
            'project' => $project->id, 'page' => $one['id'],
        ]), ['content' => '<p>Body of one.</p>'])->assertOk();

        $bootstrap = $this->actingAs($owner)->get(route('projects.pages.show', [
            'project' => $project->id, 'page' => $one['id'],
        ]))->assertOk()->viewData('bootstrap');

        // The listing carries every page's metadata but only the open page's body — fifty
        // documents in a list is no reason to ship fifty documents.
        $this->assertSame($one['id'], $bootstrap['pageId']);
        $this->assertStringContainsString('Body of one.', $bootstrap['content']);
        $this->assertCount(2, $bootstrap['pages']);
        foreach ($bootstrap['pages'] as $card) {
            $this->assertArrayNotHasKey('content', $card);
        }
    }

    public function test_a_page_from_another_project_is_not_found(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->enabled($owner, $ws, 'MINE');
        $other = $this->enabled($owner, $ws, 'THEIRS', 'Other');
        $foreign = $this->page($owner, $other, 'Theirs');

        // 404 rather than 403: a response should never confirm that a page exists somewhere
        // the user cannot see.
        $this->actingAs($owner)->get(route('projects.pages.show', [
            'project' => $project->id, 'page' => $foreign['id'],
        ]))->assertNotFound();
    }

    // ================= §9: archive =================

    public function test_archiving_takes_a_page_out_of_the_list_without_losing_it(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->enabled($owner, $ws);
        $page = $this->page($owner, $project, 'Old research');

        $card = $this->actingAs($owner)->postJson(route('projects.pages.archive', [
            'project' => $project->id, 'page' => $page['id'],
        ]))->assertOk()->json('page');

        $this->assertTrue($card['archived']);
        // Archiving answers "is this still current?", not "is this ready?" — the draft or
        // published status is untouched by it.
        $this->assertSame('draft', $card['status']);
        $this->assertTrue($ws->run(fn () => ProjectPage::whereKey($page['id'])->exists()));

        // Reversible.
        $card = $this->actingAs($owner)->postJson(route('projects.pages.archive', [
            'project' => $project->id, 'page' => $page['id'],
        ]))->assertOk()->json('page');
        $this->assertFalse($card['archived']);
    }

    // ================= §9: draft and published =================

    public function test_a_page_starts_as_a_draft_and_is_published_deliberately(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->enabled($owner, $ws);

        // §9: documentation is written before it is ready to be read, so a new page is a
        // draft. Defaulting to published would announce every half-finished note.
        $page = $this->page($owner, $project, 'Project Requirements');
        $this->assertSame('draft', $page['status']);
        $this->assertFalse($page['published']);
        $this->assertSame('Draft', $page['status_label']);

        $card = $this->actingAs($owner)->patchJson(route('projects.pages.update', [
            'project' => $project->id, 'page' => $page['id'],
        ]), ['status' => 'published'])->assertOk()->json('page');

        $this->assertSame('published', $card['status']);
        $this->assertTrue($card['published']);

        // …and back again.
        $card = $this->actingAs($owner)->patchJson(route('projects.pages.update', [
            'project' => $project->id, 'page' => $page['id'],
        ]), ['status' => 'draft'])->assertOk()->json('page');
        $this->assertSame('draft', $card['status']);
    }

    public function test_only_the_configured_statuses_are_accepted(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->enabled($owner, $ws);
        $page = $this->page($owner, $project, 'Notes');

        $this->actingAs($owner)->patchJson(route('projects.pages.update', [
            'project' => $project->id, 'page' => $page['id'],
        ]), ['status' => 'live'])->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_the_title_and_status_save_together_without_touching_the_body(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->enabled($owner, $ws);
        $page = $this->page($owner, $project, 'Untitled');

        $this->actingAs($owner)->patchJson(route('projects.pages.update', [
            'project' => $project->id, 'page' => $page['id'],
        ]), ['content' => '<p>The requirements.</p>'])->assertOk();

        // The header's edit dialog sends title and status together and nothing else — the
        // body is the autosave's, and a dialog that carried a stale copy of it would undo
        // whatever was typed while the dialog was open.
        $card = $this->actingAs($owner)->patchJson(route('projects.pages.update', [
            'project' => $project->id, 'page' => $page['id'],
        ]), ['title' => 'Project Requirements', 'status' => 'published'])->assertOk()->json('page');

        $this->assertSame('Project Requirements', $card['title']);
        $this->assertSame('published', $card['status']);

        $stored = $ws->run(fn () => ProjectPage::find($page['id']));
        $this->assertStringContainsString('The requirements.', $stored->content);
    }

    public function test_the_body_autosave_never_carries_the_title(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->enabled($owner, $ws);
        $page = $this->page($owner, $project, 'Project Requirements');

        // The reverse of the case above: a body save must not send a stale title back, or
        // renaming a page while the document is dirty would silently revert.
        $this->actingAs($owner)->patchJson(route('projects.pages.update', [
            'project' => $project->id, 'page' => $page['id'],
        ]), ['content' => '<p>Body.</p>'])->assertOk();

        $this->assertSame('Project Requirements', $ws->run(fn () => ProjectPage::find($page['id'])->title));

        $screen = file_get_contents(public_path('assets/js/projects/pages.js'));
        $this->assertStringContainsString('body: { content: this.content }', $screen);
    }

    public function test_publishing_leaves_the_content_alone(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->enabled($owner, $ws);
        $page = $this->page($owner, $project, 'Project Requirements');

        $this->actingAs($owner)->patchJson(route('projects.pages.update', [
            'project' => $project->id, 'page' => $page['id'],
        ]), ['content' => '<p>The requirements.</p>'])->assertOk();

        // Status is sent on its own, so publishing cannot blank a body the client did not
        // happen to have in hand.
        $this->actingAs($owner)->patchJson(route('projects.pages.update', [
            'project' => $project->id, 'page' => $page['id'],
        ]), ['status' => 'published'])->assertOk();

        $stored = $ws->run(fn () => ProjectPage::find($page['id']));
        $this->assertSame('published', $stored->status);
        $this->assertStringContainsString('The requirements.', $stored->content);
    }

    // ================= version history =================

    public function test_a_burst_of_autosaves_becomes_one_version(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->enabled($owner, $ws);
        $page = $this->page($owner, $project, 'Project Requirements');

        // Creating the page is the first version, so the history has a floor to restore to.
        $this->assertCount(1, $this->versions($owner, $project, $page['id']));

        // The editor autosaves after every pause in typing. Consecutive saves by one author
        // fold into the version already being written — otherwise an afternoon's work buries
        // the history in near-identical rows.
        foreach (['<p>One</p>', '<p>One two</p>', '<p>One two three</p>'] as $body) {
            $this->actingAs($owner)->patchJson(route('projects.pages.update', [
                'project' => $project->id, 'page' => $page['id'],
            ]), ['content' => $body])->assertOk();
        }

        $versions = $this->versions($owner, $project, $page['id']);
        $this->assertCount(1, $versions);

        // A save that changed nothing must not produce a version either — autosave fires on a
        // timer, not only on an edit.
        $this->actingAs($owner)->patchJson(route('projects.pages.update', [
            'project' => $project->id, 'page' => $page['id'],
        ]), ['content' => '<p>One two three</p>'])->assertOk();
        $this->assertCount(1, $this->versions($owner, $project, $page['id']));
    }

    public function test_the_coalescing_window_closes_even_while_editing_continues(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->enabled($owner, $ws);
        $page = $this->page($owner, $project, 'Project Requirements');

        $this->actingAs($owner)->patchJson(route('projects.pages.update', [
            'project' => $project->id, 'page' => $page['id'],
        ]), ['content' => '<p>Morning.</p>'])->assertOk();

        $this->assertCount(1, $this->versions($owner, $project, $page['id']));

        // Age the open version past the window. The window is measured from when the version
        // was CREATED — measuring from its last write let every save push the deadline
        // forward, so a long editing session collapsed into one version that never closed.
        $window = (int) config('projects.page_version_window_minutes');
        $ws->run(fn () => ProjectPageVersion::where('project_page_id', $page['id'])
            ->update(['created_at' => now()->subMinutes($window + 1)]));

        $this->actingAs($owner)->patchJson(route('projects.pages.update', [
            'project' => $project->id, 'page' => $page['id'],
        ]), ['content' => '<p>Morning. Afternoon.</p>'])->assertOk();

        $this->assertCount(2, $this->versions($owner, $project, $page['id']));
    }

    public function test_publishing_gets_its_own_version(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->enabled($owner, $ws);
        $page = $this->page($owner, $project, 'Project Requirements');

        $this->actingAs($owner)->patchJson(route('projects.pages.update', [
            'project' => $project->id, 'page' => $page['id'],
        ]), ['content' => '<p>Ready.</p>'])->assertOk();

        $before = count($this->versions($owner, $project, $page['id']));

        // A milestone, not a keystroke: it gets its own entry however recently the body was
        // touched, so the history can answer "when did this go live?".
        $this->actingAs($owner)->patchJson(route('projects.pages.update', [
            'project' => $project->id, 'page' => $page['id'],
        ]), ['status' => 'published'])->assertOk();

        $versions = $this->versions($owner, $project, $page['id']);
        $this->assertCount($before + 1, $versions);
        $this->assertSame('published', $versions[0]['status']);
    }

    public function test_a_different_author_starts_a_new_version(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->enabled($owner, $ws);
        $page = $this->page($owner, $project, 'Project Requirements');

        $sam = $this->member($ws, 'member', 'sam@example.com');
        $ws->run(fn () => ProjectMember::create([
            'tenant_id' => $ws->id, 'project_id' => $project->id,
            'user_id' => $sam->id, 'role' => ProjectMember::ROLE_CONTRIBUTOR,
        ]));

        $this->actingAs($owner)->patchJson(route('projects.pages.update', [
            'project' => $project->id, 'page' => $page['id'],
        ]), ['content' => '<p>Mine.</p>'])->assertOk();

        $this->actingAs($sam)->patchJson(route('projects.pages.update', [
            'project' => $project->id, 'page' => $page['id'],
        ]), ['content' => '<p>Mine. And theirs.</p>'])->assertOk();

        // Attribution is what makes the coalescing window safe: two people editing in turn
        // always get their own versions, however fast they swap.
        $versions = $this->versions($owner, $project, $page['id']);
        $this->assertCount(2, $versions);
        $this->assertSame($sam->id, $versions[0]['edited_by']['id']);
        $this->assertSame($owner->id, $versions[1]['edited_by']['id']);
    }

    public function test_restoring_a_version_brings_its_content_back_without_losing_the_current_one(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->enabled($owner, $ws);
        $page = $this->page($owner, $project, 'Draft notes');

        $this->actingAs($owner)->patchJson(route('projects.pages.update', [
            'project' => $project->id, 'page' => $page['id'],
        ]), ['title' => 'First', 'content' => '<p>The first draft.</p>'])->assertOk();

        // A second author, so this lands as its own version rather than coalescing.
        $sam = $this->member($ws, 'member', 'sam@example.com');
        $ws->run(fn () => ProjectMember::create([
            'tenant_id' => $ws->id, 'project_id' => $project->id,
            'user_id' => $sam->id, 'role' => ProjectMember::ROLE_CONTRIBUTOR,
        ]));
        $this->actingAs($sam)->patchJson(route('projects.pages.update', [
            'project' => $project->id, 'page' => $page['id'],
        ]), ['title' => 'Second', 'content' => '<p>Rewritten.</p>'])->assertOk();

        $versions = $this->versions($owner, $project, $page['id']);
        $first = collect($versions)->firstWhere('title', 'First');

        $resp = $this->actingAs($owner)->postJson(route('projects.pages.versions.restore', [
            'project' => $project->id, 'page' => $page['id'], 'version' => $first['id'],
        ]))->assertOk();

        // The document is back, title and all, and the body travels with the response because
        // the editor is still showing the newer one.
        $this->assertSame('First', $resp->json('page.title'));
        $this->assertStringContainsString('The first draft.', $resp->json('content'));

        $stored = $ws->run(fn () => ProjectPage::find($page['id']));
        $this->assertSame('First', $stored->title);
        $this->assertStringContainsString('The first draft.', $stored->content);

        // Restoring is an edit, not a rewind: the state it replaced is still in the history,
        // so going back again is possible.
        $after = $resp->json('versions');
        $this->assertContains('Second', array_column($after, 'title'));
        $this->assertSame('First', $after[0]['title']);
    }

    public function test_a_version_from_another_page_cannot_be_restored(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->enabled($owner, $ws);
        $mine = $this->page($owner, $project, 'Mine');
        $theirs = $this->page($owner, $project, 'Theirs');

        $foreign = $this->versions($owner, $project, $theirs['id'])[0];

        $this->actingAs($owner)->postJson(route('projects.pages.versions.restore', [
            'project' => $project->id, 'page' => $mine['id'], 'version' => $foreign['id'],
        ]))->assertNotFound();
    }

    public function test_a_commenter_may_read_the_history_but_not_restore_from_it(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->enabled($owner, $ws);
        $page = $this->page($owner, $project, 'Project Requirements');
        $version = $this->versions($owner, $project, $page['id'])[0];

        $sam = $this->member($ws, 'member', 'sam@example.com');
        $ws->run(fn () => ProjectMember::create([
            'tenant_id' => $ws->id, 'project_id' => $project->id,
            'user_id' => $sam->id, 'role' => ProjectMember::ROLE_COMMENTER,
        ]));

        $this->actingAs($sam)->getJson(route('projects.pages.versions', [
            'project' => $project->id, 'page' => $page['id'],
        ]))->assertOk();

        // Restoring is an edit, and §11 does not give a Commenter one.
        $this->actingAs($sam)->postJson(route('projects.pages.versions.restore', [
            'project' => $project->id, 'page' => $page['id'], 'version' => $version['id'],
        ]))->assertStatus(403);
    }

    // ================= AC-07 / AC-08: disable and re-enable =================

    public function test_disabling_pages_keeps_every_page_and_re_enabling_restores_them(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->enabled($owner, $ws);
        $page = $this->page($owner, $project, 'Project Requirements');

        $this->actingAs($owner)->patchJson(route('projects.pages.update', [
            'project' => $project->id, 'page' => $page['id'],
        ]), ['content' => '<p>The requirements.</p>'])->assertOk();

        // §5: switching off asks first, once there is something to preserve.
        $this->actingAs($owner)->postJson(route('projects.settings.features.toggle', $project), [
            'feature' => 'pages', 'enabled' => false,
        ])->assertStatus(409)->assertJsonPath('confirm', true)->assertJsonPath('count', 1);

        $this->togglePages($owner, $project, false);

        // §5: the tab goes and the screen is unreachable…
        $tabs = $this->actingAs($owner)->get(route('projects.work-items', $project))->viewData('tabs');
        $this->assertNotContains('pages', array_column($tabs, 'key'));
        $this->actingAs($owner)->get(route('projects.pages', $project))->assertNotFound();
        $this->actingAs($owner)->get(route('projects.pages.show', [
            'project' => $project->id, 'page' => $page['id'],
        ]))->assertNotFound();

        // AC-07: …but nothing is deleted, content and all.
        $stored = $ws->run(fn () => ProjectPage::find($page['id']));
        $this->assertNotNull($stored);
        $this->assertSame('Project Requirements', $stored->title);
        $this->assertStringContainsString('The requirements.', $stored->content);

        // …and nothing new can be created while it is off (§5).
        $this->actingAs($owner)->postJson(route('projects.pages.store', $project), ['title' => 'Nope'])
            ->assertStatus(403);

        // AC-08/§6: re-enabling brings the tab, the access and every page back, with nothing
        // to migrate or recreate.
        $this->enablePages($owner, $project);
        $bootstrap = $this->actingAs($owner)->get(route('projects.pages', $project))
            ->assertOk()->viewData('bootstrap');
        $this->assertSame(['Project Requirements'], array_column($bootstrap['pages'], 'title'));
        $this->actingAs($owner)->postJson(route('projects.pages.store', $project), ['title' => 'Another'])
            ->assertStatus(201);
    }

    // ================= §11: permissions =================

    public function test_a_commenter_may_read_pages_but_not_write_them(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->enabled($owner, $ws);
        $page = $this->page($owner, $project, 'Project Requirements');

        $sam = $this->member($ws, 'member', 'sam@example.com');
        $ws->run(fn () => ProjectMember::create([
            'tenant_id' => $ws->id, 'project_id' => $project->id,
            'user_id' => $sam->id, 'role' => ProjectMember::ROLE_COMMENTER,
        ]));

        // §11: a Commenter views pages…
        $this->actingAs($sam)->get(route('projects.pages', $project))->assertOk();
        $this->actingAs($sam)->get(route('projects.pages.show', [
            'project' => $project->id, 'page' => $page['id'],
        ]))->assertOk();

        // …but does not create or edit them.
        $this->actingAs($sam)->postJson(route('projects.pages.store', $project), ['title' => 'Nope'])
            ->assertStatus(403);
        $this->actingAs($sam)->patchJson(route('projects.pages.update', [
            'project' => $project->id, 'page' => $page['id'],
        ]), ['title' => 'Renamed'])->assertStatus(403);
    }

    public function test_a_contributor_writes_pages_but_only_an_admin_deletes_them(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->enabled($owner, $ws);
        $page = $this->page($owner, $project, 'Project Requirements');

        $sam = $this->member($ws, 'member', 'sam@example.com');
        $ws->run(fn () => ProjectMember::create([
            'tenant_id' => $ws->id, 'project_id' => $project->id,
            'user_id' => $sam->id, 'role' => ProjectMember::ROLE_CONTRIBUTOR,
        ]));

        // §11: a Contributor creates and edits…
        $this->actingAs($sam)->postJson(route('projects.pages.store', $project), ['title' => 'Notes'])
            ->assertStatus(201);
        $this->actingAs($sam)->patchJson(route('projects.pages.update', [
            'project' => $project->id, 'page' => $page['id'],
        ]), ['content' => '<p>Edited.</p>'])->assertOk();

        // …but §11 reserves Delete for the Project Admin.
        $this->actingAs($sam)->deleteJson(route('projects.pages.destroy', [
            'project' => $project->id, 'page' => $page['id'],
        ]))->assertStatus(403);

        $this->actingAs($owner)->deleteJson(route('projects.pages.destroy', [
            'project' => $project->id, 'page' => $page['id'],
        ]))->assertOk();
    }

    // ================= AC-09 / AC-10: mentions are Phase 2 =================

    public function test_mentions_are_surfaced_as_coming_soon(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->enabled($owner, $ws);
        $page = $this->page($owner, $project, 'Notes');

        // AC-09: nothing about Phase 1 depends on mentions. AC-10: where they would appear,
        // the editor says they are coming rather than pretending they work.
        $bootstrap = $this->actingAs($owner)->get(route('projects.pages.show', [
            'project' => $project->id, 'page' => $page['id'],
        ]))->assertOk()->viewData('bootstrap');

        $this->assertTrue($bootstrap['mentionsComingSoon']);
    }

    public function test_the_editor_ships_its_own_document_chrome(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->enabled($owner, $ws);
        $page = $this->page($owner, $project, 'Project Requirements');

        $html = $this->actingAs($owner)->get(route('projects.pages.show', [
            'project' => $project->id, 'page' => $page['id'],
        ]))->assertOk()->getContent();

        // pages.css strips Snow's field chrome so the editor reads as a document, and it has
        // to load AFTER Quill's own stylesheet to win — the same load-order rule the grid
        // skin lives by.
        $quill = strpos($html, 'quill.snow.css');
        $pages = strpos($html, 'assets/css/pages.css');
        $this->assertNotFalse($quill);
        $this->assertNotFalse($pages);
        $this->assertLessThan($pages, $quill, 'pages.css must load after quill.snow.css');

        // The toolbar is hosted in the page header, so work-items.js has to be here for
        // <wi-editor> — without it the screen renders nothing at all.
        $this->assertStringContainsString('assets/js/projects/work-items.js', $html);
        $this->assertFileExists(public_path('assets/css/pages.css'));
    }

    public function test_the_pages_editor_prefers_jodit_and_falls_back_to_quill(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->enabled($owner, $ws);
        $page = $this->page($owner, $project, 'Project Requirements');

        $html = $this->actingAs($owner)->get(route('projects.pages.show', [
            'project' => $project->id, 'page' => $page['id'],
        ]))->assertOk()->getContent();

        // The editor component always ships; which one renders is decided client-side.
        $this->assertStringContainsString('assets/js/projects/page-editor.js', $html);

        // Jodit Pro is licensed, so the package may or may not be vendored. The page must
        // work either way: the assets load only when the files are actually there, and the
        // screen falls back to <wi-editor> when they are not.
        $vendored = file_exists(public_path('assets/vendor/jodit/jodit.fat.min.js'));
        if ($vendored) {
            $this->assertStringContainsString('assets/vendor/jodit/jodit.fat.min.js', $html);

            // The FAT build specifically. The slim one keeps its Pro plugins as separate
            // files loaded from a relative `plugins/` path that does not exist once vendored,
            // so every Pro feature silently fails to appear.
            $this->assertFileDoesNotExist(public_path('assets/vendor/jodit/jodit.min.js'));

            // Pro plugins are inert without a licence, which looks like they are missing.
            $bootstrap = $this->actingAs($owner)->get(route('projects.pages.show', [
                'project' => $project->id, 'page' => $page['id'],
            ]))->viewData('bootstrap');
            $this->assertArrayHasKey('editorLicense', $bootstrap);

            // The toolbar is MOVED into the page's own host, never rebuilt there: its buttons
            // carry Jodit's handlers, and a Vue-rendered copy would put the two in a fight
            // over the same nodes — the failure that broke the Quill editor entirely.
            $component = file_get_contents(public_path('assets/js/projects/page-editor.js'));
            $this->assertStringContainsString('host.appendChild(toolbar)', $component);
            $this->assertStringContainsString('toolbarSticky: false', $component);

            // Document view: the text is laid out on a page inside Jodit's own iframe. The
            // stylesheet is APPENDED to Jodit's default, never replacing it — the default
            // carries the base typography and the page-break plugin appends to the same
            // option, so an overwrite would silently drop both.
            $this->assertStringContainsString('options.iframe = true', $component);
            $this->assertStringContainsString('window.Jodit.defaultOptions.iframeStyle', $component);
        } else {
            $this->assertStringNotContainsString('jodit', $html);
            // …and Quill is still present to fall back to.
            $this->assertStringContainsString('quill.js', $html);
        }
    }

    // ================= linked from work items =================

    public function test_a_work_item_links_several_pages_and_unlinks_them(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->enabled($owner, $ws);
        $this->actingAs($owner)->get(route('projects.work-items', $project));

        $spec = $this->publishedPage($owner, $project, 'The spec');
        $notes = $this->publishedPage($owner, $project, 'Meeting notes');
        $item = $this->workItem($owner, $project, 'Build the thing');

        $url = ['project' => $project->id, 'workItem' => $item->id];

        // Several at once: a work item cites a spec, a decision record and the meeting it was
        // agreed in, which is why this is a link rather than a column.
        $structure = $this->actingAs($owner)->postJson(route('projects.work-items.pages.store', $url), [
            'page_ids' => [$spec['id'], $notes['id']],
        ])->assertOk()->json('structure');

        $this->assertEqualsCanonicalizing(['The spec', 'Meeting notes'], array_column($structure['pages'], 'title'));

        // Linking the same page twice is a no-op, not a second row.
        $structure = $this->actingAs($owner)->postJson(route('projects.work-items.pages.store', $url), [
            'page_ids' => [$spec['id']],
        ])->assertOk()->json('structure');
        $this->assertCount(2, $structure['pages']);

        // Unlinking removes the link and nothing else — the page is documentation in its own
        // right and outlives any work item pointing at it.
        $structure = $this->actingAs($owner)->deleteJson(route('projects.work-items.pages.destroy',
            $url + ['page' => $spec['id']]))->assertOk()->json('structure');

        $this->assertSame(['Meeting notes'], array_column($structure['pages'], 'title'));
        $this->assertTrue($ws->run(fn () => ProjectPage::whereKey($spec['id'])->exists()));
    }

    public function test_the_picker_offers_this_projects_pages_and_marks_the_linked_ones(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->enabled($owner, $ws, 'MINE');
        $other = $this->enabled($owner, $ws, 'THEIRS', 'Other');
        $this->actingAs($owner)->get(route('projects.work-items', $project));

        $mine = $this->publishedPage($owner, $project, 'Mine');
        $this->publishedPage($owner, $other, 'Theirs');
        $item = $this->workItem($owner, $project, 'Build the thing');

        $url = ['project' => $project->id, 'workItem' => $item->id];

        $items = $this->actingAs($owner)->getJson(route('projects.work-items.pages.search', $url))
            ->assertOk()->json('items');

        // Pages are project-scoped, so another project's documentation is not on offer.
        $this->assertSame(['Mine'], array_column($items, 'title'));
        $this->assertFalse($items[0]['linked']);

        $this->actingAs($owner)->postJson(route('projects.work-items.pages.store', $url), [
            'page_ids' => [$mine['id']],
        ])->assertOk();

        $items = $this->actingAs($owner)->getJson(route('projects.work-items.pages.search', $url))
            ->assertOk()->json('items');
        $this->assertTrue($items[0]['linked']);

        // …and a crafted payload cannot reach across projects either.
        $foreign = $this->publishedPage($owner, $other, 'Another of theirs');
        $structure = $this->actingAs($owner)->postJson(route('projects.work-items.pages.store', $url), [
            'page_ids' => [$foreign['id']],
        ])->assertOk()->json('structure');
        $this->assertSame(['Mine'], array_column($structure['pages'], 'title'));
    }

    public function test_only_published_pages_are_offered_for_linking(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->enabled($owner, $ws);
        $this->actingAs($owner)->get(route('projects.work-items', $project));

        $draft = $this->page($owner, $project, 'Still writing');
        $published = $this->publishedPage($owner, $project, 'Ready to read');
        $item = $this->workItem($owner, $project, 'Build the thing');
        $url = ['project' => $project->id, 'workItem' => $item->id];

        // A draft is still being written; pointing a work item at one links to something its
        // author has not said is ready.
        $items = $this->actingAs($owner)->getJson(route('projects.work-items.pages.search', $url))
            ->assertOk()->json('items');
        $this->assertSame(['Ready to read'], array_column($items, 'title'));

        // A page already linked keeps showing on the work item even if it goes back to draft —
        // that is history, and the filter is only about what may be chosen now.
        $this->actingAs($owner)->postJson(route('projects.work-items.pages.store', $url), [
            'page_ids' => [$published['id']],
        ])->assertOk();

        $this->actingAs($owner)->patchJson(route('projects.pages.update', [
            'project' => $project->id, 'page' => $published['id'],
        ]), ['status' => 'draft'])->assertOk();

        $structure = $this->actingAs($owner)->getJson(route('projects.work-items.structure', $url))
            ->assertOk()->json('structure');
        $this->assertSame(['Ready to read'], array_column($structure['pages'], 'title'));

        // …and the draft is gone from the picker.
        $this->assertSame([], $this->actingAs($owner)->getJson(
            route('projects.work-items.pages.search', $url))->assertOk()->json('items'));

        $this->assertNotNull($draft);
    }

    public function test_linking_pages_needs_the_feature_and_edit_permission(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->enabled($owner, $ws);
        $this->actingAs($owner)->get(route('projects.work-items', $project));
        $page = $this->publishedPage($owner, $project, 'The spec');
        $item = $this->workItem($owner, $project, 'Build the thing');
        $url = ['project' => $project->id, 'workItem' => $item->id];

        $sam = $this->member($ws, 'member', 'sam@example.com');
        $ws->run(fn () => ProjectMember::create([
            'tenant_id' => $ws->id, 'project_id' => $project->id,
            'user_id' => $sam->id, 'role' => ProjectMember::ROLE_COMMENTER,
        ]));

        // Linking is an edit of the work item, and a Commenter does not have one.
        $this->actingAs($sam)->postJson(route('projects.work-items.pages.store', $url), [
            'page_ids' => [$page['id']],
        ])->assertStatus(403);

        // With Pages switched off there is nothing to offer and nothing to accept.
        $this->togglePages($owner, $project, false);
        $this->assertSame([], $this->actingAs($owner)->getJson(
            route('projects.work-items.pages.search', $url))->assertOk()->json('items'));
        $this->actingAs($owner)->postJson(route('projects.work-items.pages.store', $url), [
            'page_ids' => [$page['id']],
        ])->assertStatus(403);

        // The work item screen is told, so the control says why instead of doing nothing.
        $this->assertFalse($this->actingAs($owner)->get(route('projects.work-items', $project))
            ->assertOk()->viewData('bootstrap')['pagesEnabled']);
    }

    // ------------------------------------------------------------------ helpers

    private function enabled(User $owner, Workspace $ws, string $identifier = 'TESTI', string $name = 'Website Redesign'): Project
    {
        $project = $this->makeProject($owner, $ws, ['identifier' => $identifier, 'name' => $name]);
        $this->enablePages($owner, $project);

        return $project;
    }

    private function enablePages(User $owner, Project $project): void
    {
        $this->togglePages($owner, $project, true);
    }

    private function togglePages(User $owner, Project $project, bool $enabled): void
    {
        $this->actingAs($owner)->postJson(route('projects.settings.features.toggle', $project), [
            'feature' => 'pages', 'enabled' => $enabled, 'confirm' => true,
        ])->assertOk();
    }

    /** @return array<int, array<string, mixed>> */
    private function versions(User $actor, Project $project, int $pageId): array
    {
        return $this->actingAs($actor)->getJson(route('projects.pages.versions', [
            'project' => $project->id, 'page' => $pageId,
        ]))->assertOk()->json('versions');
    }

    private function workItem(User $actor, Project $project, string $title): WorkItem
    {
        $id = $this->actingAs($actor)
            ->postJson(route('projects.work-items.store', $project), ['title' => $title])
            ->assertStatus(201)->json('item.id');

        return WorkItem::withoutTenancy()->find($id);
    }

    /**
     * A page that may be linked. Pages start as drafts (§9) and the picker offers only
     * published ones, so anything meant to be linkable has to be published first.
     *
     * @return array<string, mixed>
     */
    private function publishedPage(User $actor, Project $project, string $title): array
    {
        $page = $this->page($actor, $project, $title);

        return $this->actingAs($actor)->patchJson(route('projects.pages.update', [
            'project' => $project->id, 'page' => $page['id'],
        ]), ['status' => 'published'])->assertOk()->json('page');
    }

    /** @return array<string, mixed> */
    private function page(User $actor, Project $project, string $title): array
    {
        return $this->actingAs($actor)
            ->postJson(route('projects.pages.store', $project), ['title' => $title])
            ->assertStatus(201)->json('page');
    }
}
