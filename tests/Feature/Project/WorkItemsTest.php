<?php

namespace Tests\Feature\Project;

use App\Mail\WorkItemAssignedMail;
use App\Models\Project;
use App\Models\ProjectItemLabel;
use App\Models\ProjectItemState;
use App\Models\ProjectMember;
use App\Models\WorkItem;
use App\Models\WorkItemActivity;
use App\Models\WorkItemMedia;
use App\Services\WorkItemCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Project Workspace → Work Items (Phase 5, slice 1): the tab shell, the state-grouped list,
 * and creation with workspace-unique ID numbers. Covers the MVP acceptance criteria in §13
 * that this slice claims; the detail drawer, relations and activity feeds land in later
 * slices.
 */
class WorkItemsTest extends ProjectTestCase
{
    use RefreshDatabase;

    public function test_opening_a_project_lands_on_work_items(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI']);

        // §11.1: selecting a project opens its Project Workspace, Work Items selected.
        $this->actingAs($owner)->get(route('projects.show', $project))
            ->assertRedirect(route('projects.work-items', $project));
    }

    public function test_work_items_screen_renders_the_workspace_tabs(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI']);

        $response = $this->actingAs($owner)->get(route('projects.work-items', $project))->assertOk();

        foreach (['Overview', 'Work items', 'Views'] as $label) {
            $response->assertSee($label, false);
        }

        // Cycles, Modules, Epics and Pages are feature-gated per project and are covered by
        // their own tests; all four are absent here because this project has none of them on.
        $keys = collect($response->viewData('tabs'))->pluck('key');
        foreach (['cycles', 'modules', 'epics', 'pages'] as $gated) {
            $this->assertFalse($keys->contains($gated), $gated);
        }
    }

    public function test_non_mvp_tabs_show_coming_soon_and_work_items_is_not_one(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI']);

        // Cycles, Modules, Epics and Pages are deliberately not in this list: all four are
        // built features with their own controllers, routed ahead of the Coming Soon
        // catch-all. Only Overview and Views are still placeholders.
        foreach (['overview', 'views'] as $tab) {
            $this->actingAs($owner)
                ->get(route('projects.workspace.tab', ['project' => $project->id, 'tab' => $tab]))
                ->assertOk()
                ->assertSee('coming soon', false);
        }

        // The active tab must not resolve through the Coming Soon route.
        $this->actingAs($owner)
            ->get(url("/projects/{$project->id}/work-items").'?x=1')
            ->assertOk()
            ->assertDontSee('is coming soon', false);
    }

    public function test_opening_work_items_seeds_the_projects_default_states(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI']);

        $this->assertSame(0, $ws->run(fn () => ProjectItemState::where('project_id', $project->id)->count()));

        $this->actingAs($owner)->get(route('projects.work-items', $project))
            ->assertOk()
            ->assertViewHas('bootstrap', function ($b) {
                $names = array_column($b['states'], 'name');

                return $names === ['Backlog', 'Todo', 'In Progress', 'Done', 'Cancelled'];
            });
    }

    public function test_created_work_items_get_sequential_unique_id_numbers(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI']);

        // The ID is a plain number — no project prefix (WI-006).
        $first = $this->actingAs($owner)
            ->postJson(route('projects.work-items.store', $project), ['title' => 'Welcome 👋'])
            ->assertStatus(201)
            ->assertJsonPath('item.identifier', '1')
            ->json('item');

        $this->actingAs($owner)
            ->postJson(route('projects.work-items.store', $project), ['title' => 'Second item'])
            ->assertStatus(201)
            ->assertJsonPath('item.identifier', '2');

        // A new work item lands in the project's default state (Backlog).
        $this->assertSame('Backlog', $first['state']['name']);

        $item = $ws->run(fn () => WorkItem::find($first['id']));
        $this->assertSame(1, $item->sequence_no);
        $this->assertSame($project->id, $item->project_id);
        $this->assertSame($owner->id, $item->created_by);
    }

    public function test_id_numbers_are_unique_within_a_workspace_and_never_reused(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'WEB']);

        // Six saves in a row, as "Create more" produces.
        for ($i = 1; $i <= 6; $i++) {
            $this->actingAs($owner)
                ->postJson(route('projects.work-items.store', $project), ['title' => "Item {$i}"])
                ->assertStatus(201);
        }

        $ids = $ws->run(fn () => WorkItem::forProject($project->id)->orderBy('id')->pluck('identifier')->all());
        $this->assertSame(['1', '2', '3', '4', '5', '6'], $ids);
        $this->assertCount(6, array_unique($ids));

        // Deleting an item must NOT free its number for reuse — the counter only moves
        // forward, so an ID always refers to one work item for the life of the workspace
        // (a reused ID would break links, mentions and history).
        $ws->run(fn () => WorkItem::forProject($project->id)->orderByDesc('id')->first()->delete());
        $this->actingAs($owner)
            ->postJson(route('projects.work-items.store', $project), ['title' => 'After delete'])
            ->assertStatus(201)
            ->assertJsonPath('item.identifier', '7');
    }

    public function test_id_numbers_are_shared_across_projects_but_not_across_workspaces(): void
    {
        [$owner, $ws] = $this->owner();
        $a = $this->makeProject($owner, $ws, ['identifier' => 'AAA']);
        $b = $this->makeProject($owner, $ws, ['name' => 'Other', 'identifier' => 'BBB']);

        // One counter per workspace: the number identifies the item regardless of project.
        $this->actingAs($owner)->postJson(route('projects.work-items.store', $a), ['title' => 'A1'])
            ->assertJsonPath('item.identifier', '1');
        $this->actingAs($owner)->postJson(route('projects.work-items.store', $b), ['title' => 'B1'])
            ->assertJsonPath('item.identifier', '2');
        $this->actingAs($owner)->postJson(route('projects.work-items.store', $a), ['title' => 'A2'])
            ->assertJsonPath('item.identifier', '3');

        // A different workspace numbers from 1 again — IDs are workspace-unique, and one
        // tenant's volume must not be inferable from another's (CLAUDE.md §7).
        [$other, $otherWs] = $this->owner('other-co');
        $otherProject = $this->makeProject($other, $otherWs, ['identifier' => 'OTH']);
        $this->actingAs($other)->postJson(route('projects.work-items.store', $otherProject), ['title' => 'O1'])
            ->assertJsonPath('item.identifier', '1');
    }

    public function test_a_work_item_can_be_created_with_every_supported_property(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI']);

        // Seed the state set, then pick a non-default state plus a label.
        $this->actingAs($owner)->get(route('projects.work-items', $project));
        [$stateId, $labelId] = $ws->run(function () use ($project) {
            $state = ProjectItemState::where('project_id', $project->id)->where('name', 'In Progress')->first();
            $label = ProjectItemLabel::create(['project_id' => $project->id, 'name' => 'bug', 'color' => '#EF4444', 'position' => 0]);

            return [$state->id, $label->id];
        });
        $parent = $this->actingAs($owner)
            ->postJson(route('projects.work-items.store', $project), ['title' => 'Parent'])->json('item');

        $this->actingAs($owner)
            ->postJson(route('projects.work-items.store', $project), [
                'title' => 'Build the login flow',
                'description' => 'With SSO.',
                'state_id' => $stateId,
                'priority' => 'high',
                'start_date' => '2026-09-01',
                'due_date' => '2026-09-30',
                'parent_id' => $parent['id'],
                'assignee_ids' => [$owner->id],
                'label_ids' => [$labelId],
            ])
            ->assertStatus(201)
            ->assertJsonPath('item.title', 'Build the login flow')
            ->assertJsonPath('item.state.id', $stateId)
            ->assertJsonPath('item.priority', 'high')
            ->assertJsonPath('item.start_date', '2026-09-01')
            ->assertJsonPath('item.due_date', '2026-09-30')
            ->assertJsonPath('item.parent_id', $parent['id'])
            ->assertJsonPath('item.assignees.0.id', $owner->id)
            ->assertJsonPath('item.labels.0.id', $labelId);
    }

    public function test_title_is_required_and_dates_must_be_ordered(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI']);

        $this->actingAs($owner)
            ->postJson(route('projects.work-items.store', $project), ['title' => '  '])
            ->assertStatus(422)->assertJsonValidationErrors('title');

        // The due date must be strictly AFTER the start date (§4.3).
        $this->actingAs($owner)
            ->postJson(route('projects.work-items.store', $project), [
                'title' => 'Bad dates', 'start_date' => '2026-09-30', 'due_date' => '2026-09-01',
            ])
            ->assertStatus(422)->assertJsonValidationErrors('due_date');

        // Same day is not "after" — rejected, matching the picker, which disables the start
        // day and everything before it in the due-date calendar.
        $this->actingAs($owner)
            ->postJson(route('projects.work-items.store', $project), [
                'title' => 'Same day', 'start_date' => '2026-09-01', 'due_date' => '2026-09-01',
            ])
            ->assertStatus(422)->assertJsonValidationErrors('due_date');

        // One day later is fine.
        $this->actingAs($owner)
            ->postJson(route('projects.work-items.store', $project), [
                'title' => 'Next day', 'start_date' => '2026-09-01', 'due_date' => '2026-09-02',
            ])
            ->assertStatus(201);

        // Either date on its own carries no ordering constraint.
        $this->actingAs($owner)
            ->postJson(route('projects.work-items.store', $project), ['title' => 'Due only', 'due_date' => '2026-09-01'])
            ->assertStatus(201);
        $this->actingAs($owner)
            ->postJson(route('projects.work-items.store', $project), ['title' => 'Start only', 'start_date' => '2026-09-01'])
            ->assertStatus(201);
    }

    public function test_states_labels_and_parents_cannot_come_from_another_project(): void
    {
        [$owner, $ws] = $this->owner();
        $mine = $this->makeProject($owner, $ws, ['identifier' => 'MINE']);
        $other = $this->makeProject($owner, $ws, ['name' => 'Other', 'identifier' => 'OTHR']);

        // Give the *other* project a state, a label and a work item.
        $this->actingAs($owner)->get(route('projects.work-items', $other));
        [$otherState, $otherLabel] = $ws->run(function () use ($other) {
            return [
                ProjectItemState::where('project_id', $other->id)->first()->id,
                ProjectItemLabel::create(['project_id' => $other->id, 'name' => 'x', 'color' => '#000000', 'position' => 0])->id,
            ];
        });
        $otherItem = $this->actingAs($owner)
            ->postJson(route('projects.work-items.store', $other), ['title' => 'Theirs'])->json('item');

        $base = ['title' => 'Crafted'];

        $this->actingAs($owner)->postJson(route('projects.work-items.store', $mine), $base + ['state_id' => $otherState])
            ->assertStatus(422)->assertJsonValidationErrors('state_id');
        $this->actingAs($owner)->postJson(route('projects.work-items.store', $mine), $base + ['label_ids' => [$otherLabel]])
            ->assertStatus(422);
        $this->actingAs($owner)->postJson(route('projects.work-items.store', $mine), $base + ['parent_id' => $otherItem['id']])
            ->assertStatus(422)->assertJsonValidationErrors('parent_id');
    }

    public function test_the_list_only_shows_this_projects_live_items(): void
    {
        [$owner, $ws] = $this->owner();
        $mine = $this->makeProject($owner, $ws, ['identifier' => 'MINE']);
        $other = $this->makeProject($owner, $ws, ['name' => 'Other', 'identifier' => 'OTHR']);

        $this->actingAs($owner)->postJson(route('projects.work-items.store', $mine), ['title' => 'Visible']);
        $this->actingAs($owner)->postJson(route('projects.work-items.store', $other), ['title' => 'Other project']);
        $archived = $this->actingAs($owner)
            ->postJson(route('projects.work-items.store', $mine), ['title' => 'Archived'])->json('item');
        $ws->run(fn () => WorkItem::whereKey($archived['id'])->update(['archived_at' => now()]));

        $this->actingAs($owner)->get(route('projects.work-items', $mine))
            ->assertOk()
            ->assertViewHas('bootstrap', function ($b) {
                return array_column($b['items'], 'title') === ['Visible'];
            });
    }

    public function test_a_read_only_member_cannot_create_but_can_view(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI']);
        $viewer = $this->member($ws, 'viewer', 'viewer@example.com');
        // Two-layer model (§16): workspace membership gets them into the workspace, the
        // project role decides what they may do. Commenter reads but cannot create (§34).
        $ws->run(fn () => ProjectMember::create([
            'project_id' => $project->id, 'user_id' => $viewer->id, 'role' => ProjectMember::ROLE_COMMENTER,
        ]));

        $this->actingAs($viewer)->get(route('projects.work-items', $project))
            ->assertOk()
            ->assertViewHas('bootstrap', fn ($b) => $b['canCreate'] === false);

        $this->actingAs($viewer)
            ->postJson(route('projects.work-items.store', $project), ['title' => 'Nope'])
            ->assertForbidden();

        $this->assertSame(0, $ws->run(fn () => WorkItem::forProject($project->id)->count()));
    }

    public function test_sidebar_new_work_item_targets_a_projects_work_items_with_create_flag(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI']);

        // §4.3: the global "New work item" action. Its href points at a project's Work Items
        // with ?create=1, which the screen auto-opens on — so it works without JS, and the
        // Work Items screen intercepts the click to open the modal in place.
        $expected = route('projects.work-items', $project).'?create=1';

        foreach ([route('projects.index'), route('projects.work-items', $project)] as $url) {
            $this->actingAs($owner)->get($url)->assertOk()
                ->assertSee('id="new-work-item-btn"', false)
                ->assertSee('href="'.e($expected).'"', false);
        }
    }

    public function test_sidebar_new_work_item_is_hidden_from_read_only_members(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI']);
        $viewer = $this->member($ws, 'viewer', 'viewer@example.com');
        $ws->run(fn () => ProjectMember::create([
            'project_id' => $project->id, 'user_id' => $viewer->id, 'role' => ProjectMember::ROLE_COMMENTER,
        ]));

        $this->actingAs($viewer)->get(route('projects.work-items', $project))
            ->assertOk()
            ->assertDontSee('id="new-work-item-btn"', false);
    }

    public function test_header_actions_menu_lists_the_project_actions(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI']);

        // The ⋯ menu renders on every workspace tab, not just Work Items.
        foreach ([
            route('projects.work-items', $project),
            route('projects.workspace.tab', ['project' => $project->id, 'tab' => 'views']),
        ] as $url) {
            $response = $this->actingAs($owner)->get($url)->assertOk()->assertSee('Project actions', false);

            foreach (['Add to favorites', 'Archives', 'Settings', 'Leave project'] as $item) {
                $response->assertSee($item, false);
            }

            // Settings is the one live action this phase.
            $response->assertSee(
                'href="'.e(route('projects.settings', ['project' => $project->id, 'section' => 'general'])).'"',
                false
            );
        }
    }

    public function test_header_actions_menu_is_not_inside_a_clipping_container(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI']);

        $html = $this->actingAs($owner)->get(route('projects.work-items', $project))->getContent();

        // Anchor on markers unique to this partial — the shared sidebar also has <nav> and
        // <summary> elements earlier in the document.
        $detailsAt = strpos($html, '<details class="pb-projmenu');
        $this->assertNotFalse($detailsAt);
        $rowAt = strrpos(substr($html, 0, $detailsAt), '<div class="flex items-center gap-2 h-12');
        $tabsAt = strpos($html, 'aria-label="Project tabs"');
        $summaryAt = strpos($html, '<summary', $detailsAt);
        $this->assertNotFalse($rowAt);
        $this->assertNotFalse($tabsAt);
        // The tabs <nav> opening tag sits just before its aria-label.
        $navAt = (int) strrpos(substr($html, 0, $tabsAt), '<nav');

        // The ⋯ menu is absolutely positioned below a 48px row. If that row scrolls it
        // becomes a clipping container and the menu silently never appears — so the row must
        // not scroll, and only the tab list may.
        $this->assertStringNotContainsString(
            'overflow-x-auto',
            substr($html, $rowAt, $detailsAt - $rowAt),
            'The project header row must not be a scroll container — it would clip the ⋯ menu.'
        );
        $this->assertStringContainsString('overflow-x-auto', substr($html, $navAt, $tabsAt - $navAt));

        // `display:grid` / `display:flex` on a <summary> stops WebKit toggling the disclosure.
        $summary = substr($html, $summaryAt, (int) strpos($html, '</summary>', $summaryAt) - $summaryAt);
        $summaryTag = substr($summary, 0, (int) strpos($summary, '>'));
        $this->assertStringNotContainsString('grid', $summaryTag);
        $this->assertStringNotContainsString('flex', $summaryTag);
    }

    public function test_description_images_upload_to_a_private_disk_and_serve_through_an_authorized_url(): void
    {
        Storage::fake('local');

        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI']);

        // SunEditor posts each file as `file-N` and reads back {result: [{url, name, size}]}.
        $response = $this->actingAs($owner)->post(route('projects.work-items.media.store', $project), [
            'file-0' => UploadedFile::fake()->image('diagram.png', 400, 300),
        ])->assertOk();

        $url = $response->json('result.0.url');
        $this->assertSame('diagram.png', $response->json('result.0.name'));

        $media = $ws->run(fn () => WorkItemMedia::firstOrFail());
        $this->assertSame('local', $media->disk);
        $this->assertSame($project->id, $media->project_id);
        Storage::disk('local')->assertExists($media->path);

        // The embedded URL is our own authorized route, not a path to the file.
        $this->assertSame(route('projects.work-items.media.show', [
            'project' => $project->id, 'media' => $media->id,
        ]), $url);
        $this->actingAs($owner)->get($url)->assertOk();

        // A member of another workspace cannot read it, and is told nothing about it (§12).
        [$outsider] = $this->owner('other-co');
        $this->actingAs($outsider)->get($url)->assertNotFound();

        // Non-images and oversized files are refused in SunEditor's own error shape.
        $this->actingAs($owner)->post(route('projects.work-items.media.store', $project), [
            'file-0' => UploadedFile::fake()->create('payload.php', 8, 'application/x-php'),
        ])->assertStatus(422)->assertJsonStructure(['errorMessage']);
    }

    public function test_the_editor_gallery_only_lists_this_projects_uploads(): void
    {
        Storage::fake('local');

        [$owner, $ws] = $this->owner();
        $mine = $this->makeProject($owner, $ws, ['identifier' => 'MINE']);
        $other = $this->makeProject($owner, $ws, ['name' => 'Other', 'identifier' => 'OTH']);

        $this->actingAs($owner)->post(route('projects.work-items.media.store', $mine), [
            'file-0' => UploadedFile::fake()->image('mine.png'),
        ])->assertOk();
        $this->actingAs($owner)->post(route('projects.work-items.media.store', $other), [
            'file-0' => UploadedFile::fake()->image('theirs.png'),
        ])->assertOk();

        $gallery = $this->actingAs($owner)->getJson(route('projects.work-items.media.index', $mine))
            ->assertOk()->json('result');

        $this->assertSame(['mine.png'], array_column($gallery, 'name'));
    }

    public function test_a_read_only_member_cannot_upload_editor_images(): void
    {
        Storage::fake('local');

        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI', 'visibility' => 'public']);
        $viewer = $this->member($ws, 'viewer', 'viewer@example.com');

        $this->actingAs($viewer)->post(route('projects.work-items.media.store', $project), [
            'file-0' => UploadedFile::fake()->image('nope.png'),
        ])->assertStatus(403)->assertJsonStructure(['errorMessage']);

        $this->assertSame(0, $ws->run(fn () => WorkItemMedia::count()));
    }

    public function test_embedded_video_is_kept_only_for_known_providers(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI']);

        $item = $this->actingAs($owner)->postJson(route('projects.work-items.store', $project), [
            'title' => 'With media',
        ])->assertStatus(201)->json('item');

        // Embeds come from the editor, which writes through the detail view's PATCH.
        $description = $this->actingAs($owner)->patchJson(
            route('projects.work-items.update', ['project' => $project->id, 'workItem' => $item['id']]),
            ['description' => '<figure><iframe src="https://www.youtube.com/embed/abc" allowfullscreen></iframe></figure>'
                .'<iframe src="https://evil.test/login"></iframe>'],
        )->assertOk()->json('item.description');

        $this->assertStringContainsString('youtube.com/embed/abc', $description);
        $this->assertStringNotContainsString('evil.test', $description);
    }

    public function test_rich_text_descriptions_are_stored_sanitized(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI']);

        // The create modal has a plain textarea, so the create endpoint treats its input as
        // literal text: line breaks become markup, and anything that looks like a tag is
        // shown rather than interpreted.
        $created = $this->actingAs($owner)->postJson(route('projects.work-items.store', $project), [
            'title' => 'Quick capture',
            'description' => "First line\nsecond line\n\nNew paragraph <script>alert(1)</script>",
        ])->assertStatus(201)->json('item');

        $this->assertStringContainsString('<br />', $created['description']);
        $this->assertStringContainsString('&lt;script&gt;', $created['description']);
        $this->assertStringNotContainsString('<script>', $created['description']);

        // The detail view DOES use the editor, so its PATCH keeps real markup — and drops
        // everything a hostile paste could smuggle in with it.
        $item = $this->actingAs($owner)->postJson(route('projects.work-items.store', $project), [
            'title' => 'Rich',
        ])->assertStatus(201)->json('item');

        $stored = $this->actingAs($owner)->patchJson(
            route('projects.work-items.update', ['project' => $project->id, 'workItem' => $item['id']]),
            ['description' => '<p style="text-align:center">Plan <strong>this</strong></p>'
                .'<ul><li>step</li></ul>'
                .'<script>alert(1)</script>'
                .'<img src=x onerror=alert(1)>'
                .'<a href="javascript:alert(1)">bad link</a>'
                .'<p style="background:url(javascript:alert(1))">styled</p>'],
        )->assertOk()->json('item.description');

        $this->assertStringContainsString('<strong>this</strong>', $stored);
        // Quill stores alignment, indentation and list type as classes/attributes rather
        // than as tags — dropping them would silently change what the author formatted.
        $quill = $this->actingAs($owner)->patchJson(
            route('projects.work-items.update', ['project' => $project->id, 'workItem' => $item['id']]),
            ['description' => '<p class="ql-align-center">mid</p><ol><li data-list="bullet">a</li></ol>'
                .'<pre class="ql-syntax">x</pre><p><span style="color: rgb(230,0,0);">red</span></p>'],
        )->assertOk()->json('item.description');
        $this->assertStringContainsString('ql-align-center', $quill);
        $this->assertStringContainsString('data-list="bullet"', $quill);
        $this->assertStringContainsString('ql-syntax', $quill);
        $this->assertStringContainsString('color: rgb(230,0,0)', $quill);
        $this->assertStringContainsString('<li>step</li>', $stored);
        $this->assertStringContainsString('text-align:center', $stored);
        $this->assertStringNotContainsString('<script', $stored);
        $this->assertStringNotContainsString('onerror', $stored);
        $this->assertStringNotContainsString('javascript:', $stored);

        // Emptying the editor stores an empty description, not its scaffolding.
        $url = route('projects.work-items.update', ['project' => $project->id, 'workItem' => $item['id']]);
        $this->actingAs($owner)->patchJson($url, ['description' => '<p><br></p>'])
            ->assertOk()->assertJsonPath('item.description', null);

        // History keeps a readable excerpt rather than a wall of markup (§6).
        $entry = collect($this->actingAs($owner)->getJson(route('projects.work-items.activity', [
            'project' => $project->id, 'workItem' => $item['id'],
        ]))->json('activity'))
            ->first(fn ($a) => $a['field'] === 'description' && str_contains((string) $a['old_value'], 'Plan this'));
        $this->assertNotNull($entry, 'The description change should be recorded as an excerpt.');
        // An excerpt, not the markup it was taken from.
        $this->assertStringNotContainsString('<', (string) $entry['old_value']);
    }

    public function test_a_work_item_has_exactly_one_assignee(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI']);
        $alice = $this->member($ws, 'member', 'alice@example.com');
        $bob = $this->member($ws, 'member', 'bob@example.com');

        // Creating with two assignees is refused outright (§4.3, revised).
        $this->actingAs($owner)->postJson(route('projects.work-items.store', $project), [
            'title' => 'Two owners', 'assignee_ids' => [$alice->id, $bob->id],
        ])->assertStatus(422)->assertJsonValidationErrors('assignee_ids');

        $alice->forceFill(['avatar_url' => 'https://cdn.test/alice.png'])->save();

        $item = $this->actingAs($owner)->postJson(route('projects.work-items.store', $project), [
            'title' => 'One owner', 'assignee_ids' => [$alice->id],
        ])->assertStatus(201)->json('item');
        $this->assertSame([$alice->id], array_column($item['assignees'], 'id'));

        // The list row draws the assignee's photo when they have one, so the payload has to
        // carry it — it used to send only the initial, so real avatars never appeared.
        $this->assertSame('https://cdn.test/alice.png', $item['assignees'][0]['avatar_url']);
        $this->assertNotEmpty($item['assignees'][0]['initial']);

        $listed = $this->actingAs($owner)->get(route('projects.work-items', $project))
            ->assertOk()->viewData('bootstrap');
        $row = collect($listed['items'])->firstWhere('id', $item['id']);
        $this->assertSame('https://cdn.test/alice.png', $row['assignees'][0]['avatar_url']);
        // …and so does the assignee picker.
        $this->assertSame('https://cdn.test/alice.png',
            collect($listed['members'])->firstWhere('id', $alice->id)['avatar_url']);

        // Reassigning REPLACES — the previous assignee must not linger.
        $url = route('projects.work-items.update', ['project' => $project->id, 'workItem' => $item['id']]);
        $updated = $this->actingAs($owner)->patchJson($url, ['assignee_ids' => [$bob->id]])
            ->assertOk()->json('item');
        $this->assertSame([$bob->id], array_column($updated['assignees'], 'id'));

        // And the server refuses a second one however the request is shaped (§80: the UI is
        // never the authority).
        $this->actingAs($owner)->patchJson($url, ['assignee_ids' => [$alice->id, $bob->id]])
            ->assertStatus(422)->assertJsonValidationErrors('assignee_ids');
    }

    public function test_the_assignee_is_emailed_when_they_are_given_a_work_item(): void
    {
        Mail::fake();

        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI']);
        $alice = $this->member($ws, 'member', 'alice@example.com');

        $item = $this->actingAs($owner)->postJson(route('projects.work-items.store', $project), [
            'title' => 'Configure stale ticket automation', 'assignee_ids' => [$alice->id],
        ])->assertStatus(201)->json('item');

        Mail::assertQueued(WorkItemAssignedMail::class, function (WorkItemAssignedMail $mail) use ($alice, $item) {
            return $mail->hasTo($alice->email)
                && $mail->identifier === $item['identifier']
                && str_contains($mail->url, (string) $item['id']);
        });

        // Re-saving the same assignee changes nothing, so nobody is emailed again.
        Mail::fake();
        $url = route('projects.work-items.update', ['project' => $project->id, 'workItem' => $item['id']]);
        $this->actingAs($owner)->patchJson($url, ['assignee_ids' => [$alice->id]])->assertOk();
        Mail::assertNothingQueued();

        // Assigning yourself is not news.
        $this->actingAs($owner)->patchJson($url, ['assignee_ids' => [$owner->id]])->assertOk();
        Mail::assertNothingQueued();
    }

    public function test_the_settings_action_lands_on_a_working_screen(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI']);

        // Every section registered in config('projects.settings_nav') must render — the
        // controller resolves the section from that list, so a missing entry 404s.
        foreach (config('projects.settings_nav') as $section) {
            $this->actingAs($owner)
                ->get(route('projects.settings', ['project' => $project->id, 'section' => $section['key']]))
                ->assertOk();
        }

        // An unknown section still 404s, and the bare URL redirects to General.
        $this->actingAs($owner)
            ->get(route('projects.settings', ['project' => $project->id, 'section' => 'nope']))
            ->assertNotFound();
        $this->actingAs($owner)
            ->get(route('projects.settings.index', $project))
            ->assertRedirect(route('projects.settings', ['project' => $project->id, 'section' => 'general']));
    }

    public function test_header_settings_action_is_hidden_without_manage_rights(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI']);
        $member = $this->member($ws, 'member', 'member@example.com');
        $ws->run(fn () => ProjectMember::create([
            'project_id' => $project->id, 'user_id' => $member->id, 'role' => ProjectMember::ROLE_CONTRIBUTOR,
        ]));

        // A project Contributor can open the project but cannot manage it — no Settings
        // link, and the settings screen rejects them anyway.
        $this->actingAs($member)->get(route('projects.work-items', $project))
            ->assertOk()
            ->assertSee('Add to favorites', false)
            ->assertDontSee(route('projects.settings', ['project' => $project->id, 'section' => 'general']), false);

        $this->actingAs($member)
            ->get(route('projects.settings', ['project' => $project->id, 'section' => 'general']))
            ->assertForbidden();
    }

    public function test_every_new_work_item_gets_a_creation_activity_entry(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI']);
        $this->actingAs($owner)->get(route('projects.work-items', $project));
        [$stateId, $labelId] = $ws->run(function () use ($project) {
            return [
                ProjectItemState::where('project_id', $project->id)->where('name', 'In Progress')->first()->id,
                ProjectItemLabel::create(['project_id' => $project->id, 'name' => 'bug', 'color' => '#EF4444', 'position' => 0])->id,
            ];
        });

        $item = $this->actingAs($owner)
            ->postJson(route('projects.work-items.store', $project), [
                'title' => 'Build the login flow',
                'state_id' => $stateId,
                'priority' => 'high',
                'start_date' => '2026-09-01',
                'due_date' => '2026-09-30',
                'assignee_ids' => [$owner->id],
                'label_ids' => [$labelId],
            ])->assertStatus(201)->json('item');

        $this->actingAs($owner)
            ->getJson(route('projects.work-items.activity', ['project' => $project->id, 'workItem' => $item['id']]))
            ->assertOk()
            ->assertJsonCount(1, 'activity')
            ->assertJsonPath('activity.0.event', 'created')
            ->assertJsonPath('activity.0.actor.id', $owner->id)
            // The snapshot records what it was created WITH, resolved to display values.
            ->assertJsonPath('activity.0.meta.snapshot.title', 'Build the login flow')
            ->assertJsonPath('activity.0.meta.snapshot.state', 'In Progress')
            ->assertJsonPath('activity.0.meta.snapshot.priority', 'high')
            ->assertJsonPath('activity.0.meta.snapshot.start_date', '2026-09-01')
            ->assertJsonPath('activity.0.meta.snapshot.due_date', '2026-09-30')
            ->assertJsonPath('activity.0.meta.snapshot.labels.0', 'bug');
    }

    public function test_a_work_item_can_never_exist_without_its_creation_entry(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI']);

        // Force a failure AFTER the row is inserted: the label FK cannot be satisfied, so
        // the whole transaction — item, sequence counter and activity — must roll back.
        $threw = false;
        try {
            $ws->run(fn () => app(WorkItemCreator::class)->create($owner, $project, [
                'title' => 'Doomed', 'label_ids' => [999999],
            ]));
        } catch (\Throwable $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'Expected the bad label reference to fail the create.');
        $ws->run(function () use ($project) {
            $this->assertSame(0, WorkItem::forProject($project->id)->count());
            $this->assertSame(0, WorkItemActivity::count());
        });
        // The ID sequence rolled back too, so the next real item is still #1.
        $this->assertSame(0, (int) $ws->fresh()->work_item_sequence);
    }

    public function test_activity_survives_renaming_the_state_it_referenced(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI']);
        $this->actingAs($owner)->get(route('projects.work-items', $project));

        $item = $this->actingAs($owner)
            ->postJson(route('projects.work-items.store', $project), ['title' => 'First'])
            ->json('item');

        // Display values are frozen at write time, so history is not silently rewritten when
        // the referenced state is renamed later.
        $ws->run(fn () => ProjectItemState::where('project_id', $project->id)
            ->where('name', 'Backlog')->update(['name' => 'Icebox']));

        $this->actingAs($owner)
            ->getJson(route('projects.work-items.activity', ['project' => $project->id, 'workItem' => $item['id']]))
            ->assertOk()
            ->assertJsonPath('activity.0.meta.snapshot.state', 'Backlog');
    }

    public function test_activity_is_scoped_to_its_project_and_readers(): void
    {
        [$owner, $ws] = $this->owner();
        $mine = $this->makeProject($owner, $ws, ['identifier' => 'MINE']);
        $secret = $this->makeProject($owner, $ws, [
            'name' => 'Secret', 'identifier' => 'SECRET', 'visibility' => Project::VISIBILITY_PRIVATE,
        ]);

        $item = $this->actingAs($owner)
            ->postJson(route('projects.work-items.store', $secret), ['title' => 'Hush'])->json('item');

        // Reachable only through its OWN project's URL, never another project's.
        $this->actingAs($owner)
            ->getJson(route('projects.work-items.activity', ['project' => $mine->id, 'workItem' => $item['id']]))
            ->assertNotFound();

        // And only by someone who can open the work item — 404, not 403 (§12).
        $outsider = $this->member($ws, 'member', 'outsider@example.com');
        $this->actingAs($outsider)
            ->getJson(route('projects.work-items.activity', ['project' => $secret->id, 'workItem' => $item['id']]))
            ->assertNotFound();

        // A Commenter on the project may still read its history (§34: view yes, change no).
        $viewer = $this->member($ws, 'viewer', 'viewer@example.com');
        $ws->run(fn () => ProjectMember::create([
            'project_id' => $mine->id, 'user_id' => $viewer->id, 'role' => ProjectMember::ROLE_COMMENTER,
        ]));
        $public = $this->actingAs($owner)
            ->postJson(route('projects.work-items.store', $mine), ['title' => 'Open'])->json('item');
        $this->actingAs($viewer)
            ->getJson(route('projects.work-items.activity', ['project' => $mine->id, 'workItem' => $public['id']]))
            ->assertOk()
            ->assertJsonPath('activity.0.event', 'created');
    }

    public function test_row_chips_patch_individual_properties_and_log_them(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'WEB']);
        $this->actingAs($owner)->get(route('projects.work-items', $project));
        [$stateId, $labelId] = $ws->run(function () use ($project) {
            return [
                ProjectItemState::where('project_id', $project->id)->where('name', 'Done')->first()->id,
                ProjectItemLabel::create(['project_id' => $project->id, 'name' => 'bug', 'color' => '#EF4444', 'position' => 0])->id,
            ];
        });
        $item = $this->actingAs($owner)
            ->postJson(route('projects.work-items.store', $project), ['title' => 'Row edits'])->json('item');
        $url = route('projects.work-items.update', ['project' => $project->id, 'workItem' => $item['id']]);

        // Each chip PATCHes only what it changed (§4.2).
        $this->actingAs($owner)->patchJson($url, ['state_id' => $stateId])
            ->assertOk()->assertJsonPath('item.state.id', $stateId);
        $this->actingAs($owner)->patchJson($url, ['priority' => 'urgent'])
            ->assertOk()->assertJsonPath('item.priority', 'urgent');
        $this->actingAs($owner)->patchJson($url, ['assignee_ids' => [$owner->id]])
            ->assertOk()->assertJsonPath('item.assignees.0.id', $owner->id);
        $this->actingAs($owner)->patchJson($url, ['label_ids' => [$labelId]])
            ->assertOk()->assertJsonPath('item.labels.0.id', $labelId);
        $this->actingAs($owner)->patchJson($url, ['start_date' => '2026-09-01'])
            ->assertOk()->assertJsonPath('item.start_date', '2026-09-01');

        // §6: every change lands in the feed, with before/after and a Transition row.
        $feed = $this->actingAs($owner)
            ->getJson(route('projects.work-items.activity', ['project' => $project->id, 'workItem' => $item['id']]))
            ->assertOk()->json('activity');

        $fields = array_column($feed, 'field');
        $this->assertSame([null, 'state', 'priority', 'assignees', 'labels', 'start_date'], $fields);
        $transition = collect($feed)->firstWhere('field', 'state');
        $this->assertSame((string) $stateId, $transition['new_value']);
        $this->assertSame('Done', $transition['meta']['new_label']);
    }

    public function test_an_unchanged_property_is_not_logged(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'WEB']);
        $item = $this->actingAs($owner)
            ->postJson(route('projects.work-items.store', $project), ['title' => 'Same'])->json('item');
        $url = route('projects.work-items.update', ['project' => $project->id, 'workItem' => $item['id']]);

        // Re-sending the current values must not manufacture history.
        $this->actingAs($owner)->patchJson($url, ['title' => 'Same', 'priority' => 'none'])->assertOk();

        $feed = $this->actingAs($owner)
            ->getJson(route('projects.work-items.activity', ['project' => $project->id, 'workItem' => $item['id']]))
            ->json('activity');
        $this->assertCount(1, $feed);
        $this->assertSame('created', $feed[0]['event']);
    }

    public function test_inline_edits_respect_the_date_rule_and_project_scope(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'WEB']);
        $other = $this->makeProject($owner, $ws, ['name' => 'Other', 'identifier' => 'OTH']);
        $item = $this->actingAs($owner)
            ->postJson(route('projects.work-items.store', $project), [
                'title' => 'Dated', 'start_date' => '2026-09-10', 'due_date' => '2026-09-20',
            ])->json('item');
        $url = route('projects.work-items.update', ['project' => $project->id, 'workItem' => $item['id']]);

        // Sending only the due date still compares against the STORED start date.
        $this->actingAs($owner)->patchJson($url, ['due_date' => '2026-09-05'])
            ->assertStatus(422)->assertJsonValidationErrors('due_date');
        $this->actingAs($owner)->patchJson($url, ['due_date' => '2026-09-25'])->assertOk();

        // A state from another project is rejected, and an item cannot parent itself.
        $foreignState = $ws->run(fn () => ProjectItemState::create([
            'project_id' => $other->id, 'name' => 'X', 'color' => '#000000', 'group' => 'backlog', 'position' => 0,
        ])->id);
        $this->actingAs($owner)->patchJson($url, ['state_id' => $foreignState])
            ->assertStatus(422)->assertJsonValidationErrors('state_id');
        $this->actingAs($owner)->patchJson($url, ['parent_id' => $item['id']])
            ->assertStatus(422)->assertJsonValidationErrors('parent_id');
    }

    public function test_row_actions_copy_archive_and_delete(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'WEB']);
        $item = $this->actingAs($owner)
            ->postJson(route('projects.work-items.store', $project), ['title' => 'Original', 'priority' => 'high'])
            ->json('item');
        $args = ['project' => $project->id, 'workItem' => $item['id']];

        // Make a copy — same properties, its own unique ID number (§4.4).
        $copy = $this->actingAs($owner)->postJson(route('projects.work-items.duplicate', $args))
            ->assertStatus(201)
            ->assertJsonPath('item.title', 'Original')
            ->assertJsonPath('item.priority', 'high')
            ->json('item');
        $this->assertSame('2', $copy['identifier']);
        $this->assertNotSame($item['id'], $copy['id']);

        // The stable per-item URL that "Open in new tab" / "Copy link" resolve to.
        // The per-item URL renders the detail as its own page (§4.4), not a redirect back
        // into the list: it has to survive being opened in a new tab or pasted to someone.
        $this->actingAs($owner)->get(route('projects.work-items.show', $args))
            ->assertOk()
            ->assertViewHas('bootstrap', fn ($b) => $b['pageItemId'] === $item['id']);

        // Archive drops it from the active list but keeps the row (§4.4).
        $this->actingAs($owner)->postJson(route('projects.work-items.archive', $args))->assertOk();
        $this->assertNotNull($ws->run(fn () => WorkItem::find($item['id'])->archived_at));
        $this->actingAs($owner)->get(route('projects.work-items', $project))
            ->assertViewHas('bootstrap', fn ($b) => array_column($b['items'], 'identifier') === ['2']);

        // Delete is permanent.
        $this->actingAs($owner)->deleteJson(route('projects.work-items.destroy', $args))->assertOk();
        $this->assertFalse($ws->run(fn () => WorkItem::whereKey($item['id'])->exists()));
    }

    public function test_row_actions_are_refused_for_read_only_roles(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'WEB']);
        $item = $this->actingAs($owner)
            ->postJson(route('projects.work-items.store', $project), ['title' => 'Protected'])->json('item');
        $args = ['project' => $project->id, 'workItem' => $item['id']];

        $commenter = $this->member($ws, 'member', 'commenter@example.com');
        $ws->run(fn () => ProjectMember::create([
            'project_id' => $project->id, 'user_id' => $commenter->id, 'role' => ProjectMember::ROLE_COMMENTER,
        ]));

        // §34: a Commenter reads the list but cannot change, copy, archive or delete.
        $this->actingAs($commenter)->get(route('projects.work-items', $project))
            ->assertOk()
            ->assertViewHas('bootstrap', fn ($b) => $b['canEdit'] === false);
        $this->actingAs($commenter)->patchJson(route('projects.work-items.update', $args), ['priority' => 'urgent'])
            ->assertForbidden();
        $this->actingAs($commenter)->postJson(route('projects.work-items.duplicate', $args))->assertForbidden();
        $this->actingAs($commenter)->postJson(route('projects.work-items.archive', $args))->assertForbidden();
        $this->actingAs($commenter)->deleteJson(route('projects.work-items.destroy', $args))->assertForbidden();
    }

    public function test_a_work_item_cannot_be_edited_through_another_projects_url(): void
    {
        [$owner, $ws] = $this->owner();
        $mine = $this->makeProject($owner, $ws, ['identifier' => 'MINE']);
        $other = $this->makeProject($owner, $ws, ['name' => 'Other', 'identifier' => 'OTH']);
        $item = $this->actingAs($owner)
            ->postJson(route('projects.work-items.store', $other), ['title' => 'Theirs'])->json('item');

        // §28: never trust the URL — the item must belong to the project in the path.
        $this->actingAs($owner)
            ->patchJson(route('projects.work-items.update', ['project' => $mine->id, 'workItem' => $item['id']]), ['priority' => 'low'])
            ->assertNotFound();
    }

    /**
     * Every screen that mounts the work item grid must load the skin AFTER Tabulator's own
     * stylesheet.
     *
     * The two collide at equal specificity, so the later one wins — and when the Cycles
     * screen listed these tags for itself, in the other order, every collapsed group row on
     * that page turned grey while the identical component looked right on Work Items. Both
     * pages now include partials/work-item-assets; this pins the property that block exists
     * to guarantee.
     */
    public function test_the_grid_skin_loads_after_tabulators_own_stylesheet(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'TESTI']);

        $pages = [route('projects.work-items', $project)];

        // The Cycles screen mounts the same grid, so it is held to the same order.
        $this->actingAs($owner)->postJson(route('projects.settings.features.toggle', $project), [
            'feature' => 'cycles', 'enabled' => true,
        ])->assertOk();
        $pages[] = route('projects.cycles', $project);

        foreach ($pages as $url) {
            $html = $this->actingAs($owner)->get($url)->assertOk()->getContent();

            $vendor = strpos($html, 'tabulator.min.css');
            $skin = strpos($html, 'assets/css/work-items.css');

            $this->assertNotFalse($vendor, "Tabulator's stylesheet is missing from {$url}");
            $this->assertNotFalse($skin, "The grid skin is missing from {$url}");
            $this->assertLessThan($skin, $vendor, "work-items.css must load after tabulator.min.css on {$url}");
        }
    }

    /**
     * The group header's "+" and a row's ⋯ form one vertical column down the grid, but they
     * are built in two different files and measured from two different boxes — the group's own
     * padding-right, and the last cell's. They drifted: 24px against 28px, `rounded` against
     * `rounded-md`. Nothing renders CSS here, so this pins what a PHP test can see — that the
     * two controls are still declared with the same box.
     */
    public function test_the_group_add_button_and_the_row_action_share_one_box(): void
    {
        $list = file_get_contents(public_path('assets/js/projects/work-item-list.js'));
        $ui = file_get_contents(public_path('assets/js/projects/work-item-ui.js'));

        $box = 'h-7 w-7 grid place-items-center rounded-md';

        $this->assertStringContainsString('data-gadd=', $list);
        $this->assertStringContainsString($box, $list, 'The group "+" no longer uses the shared box.');
        $this->assertStringContainsString($box, $ui, 'The row action no longer uses the shared box.');

        // Both measure from the same edge, 20px in — the group through its own padding, the
        // row through the last cell's.
        $css = file_get_contents(public_path('assets/css/work-items.css'));
        $this->assertStringContainsString('padding: 0 20px 0 24px;', $css);
        $this->assertStringContainsString('.tabulator-cell:last-child { padding-right: 20px; }', $css);
        // Tabulator's own 1px group border would put the two boxes 1px apart.
        $this->assertMatchesRegularExpression('/tabulator-group[^}]*border-right:\s*none/s', $css);
    }

    /**
     * Quill's `getSelection(true)` maps the native selection to a document position, and
     * throws when that selection is in a node it does not own — the toolbar, another field,
     * anything outside the editor root:
     *
     *     TypeError: Cannot read properties of null (reading 'offset')
     *
     * The paste handler called it directly, so a paste threw before inserting anything: the
     * event was cancelled AND the content dropped. Every selection read now goes through
     * safeRange(), which falls back to the end of the document. Nothing renders JS here, so
     * this pins the one thing a PHP test can see — that no call site uses the throwing form.
     */
    public function test_the_editor_never_reads_the_selection_unguarded(): void
    {
        $editor = file_get_contents(public_path('assets/js/projects/work-items.js'));

        $this->assertStringContainsString('safeRange: function ()', $editor);

        // The only place the forced read is allowed is inside safeRange itself, where it is
        // wrapped in try/catch.
        $guarded = substr_count($editor, 'this.quill.getSelection(true)');
        $this->assertSame(1, $guarded, 'getSelection(true) must only be called inside safeRange()');
        $this->assertStringNotContainsString('self.quill.getSelection(true)', $editor);
    }

    /**
     * Quill rewrites its toolbar's DOM — Snow turns every <select> into a picker — so a
     * Vue-rendered toolbar put the two in a fight over the same nodes. Each re-render patched
     * Quill's markup away, and the mutation storm threw inside Quill's own observer:
     *
     *     TypeError: Cannot read properties of null (reading 'offset')
     *       normalizedToRange → getRange → update → handleDOM
     *
     * After that Quill stopped tracking changes: no text-change, so nothing saved and paste
     * did nothing. Quill now builds the toolbar and the editor MOVES it, so the host stays an
     * element Vue renders once and never patches.
     */
    public function test_the_editor_builds_its_own_toolbar(): void
    {
        $editor = file_get_contents(public_path('assets/js/projects/work-items.js'));
        $pages = file_get_contents(public_path('assets/js/projects/pages.js'));

        // Quill always builds from its own layout; the host only receives the result.
        $this->assertStringContainsString('container: WI_EDITOR_TOOLBAR,', $editor);
        $this->assertStringContainsString('host.appendChild(built.container)', $editor);

        // …and no screen hand-writes toolbar markup for Quill to collide with.
        $this->assertStringNotContainsString('ql-formats', $pages);
    }

    /**
     * A copy from Word is HTML wrapped in conditional comments, an <xml> island, a <style>
     * block of Mso classes and `mso-…` declarations in every style attribute. Quill's matchers
     * read that literally and the paste arrives flattened, so the editor strips the
     * scaffolding first. Nothing renders JS here, so this pins that the cleaner exists and
     * still removes each piece — the regexes are easy to break and the symptom is invisible
     * until someone pastes a document.
     */
    public function test_the_editor_strips_word_scaffolding_before_pasting(): void
    {
        $editor = file_get_contents(public_path('assets/js/projects/work-items.js'));

        $this->assertStringContainsString('cleanPastedHtml: function', $editor);
        // …and it runs on the way into Quill's converter, not somewhere decorative.
        $this->assertStringContainsString('convert({ html: this.cleanPastedHtml(html) })', $editor);

        foreach ([
            'conditional comments' => '<!--[',
            'the xml island' => '<xml',
            'the style block' => '<style',
            'namespaced tags' => 'o:p',
            'mso declarations' => 'mso-',
        ] as $what => $marker) {
            $this->assertStringContainsString($marker, $editor, "the cleaner no longer mentions {$what}");
        }
    }

    public function test_a_private_project_404s_for_an_outsider(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, [
            'identifier' => 'SECRET', 'visibility' => Project::VISIBILITY_PRIVATE,
        ]);
        $outsider = $this->member($ws, 'member', 'outsider@example.com');

        // 404, never 403: do not reveal that an inaccessible project exists (spec §12).
        $this->actingAs($outsider)->get(route('projects.work-items', $project))->assertNotFound();
        $this->actingAs($outsider)
            ->get(route('projects.workspace.tab', ['project' => $project->id, 'tab' => 'views']))
            ->assertNotFound();
    }
}
