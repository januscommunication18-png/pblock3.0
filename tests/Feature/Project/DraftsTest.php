<?php

namespace Tests\Feature\Project;

use App\Models\ProjectItemState;
use App\Models\WorkItem;
use App\Models\WorkItemActivity;
use App\Models\WorkItemMedia;
use App\Models\WorkItemTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Drafts (docs/features/drafts.md) — work items captured before they have a project.
 *
 * Covers DR-01…DR-14. Two properties carry most of the weight and are asserted from several
 * angles: a draft is invisible to every work item query in the application (the ExcludesDrafts
 * global scope), and it is invisible to every user except its author.
 */
class DraftsTest extends ProjectTestCase
{
    use RefreshDatabase;

    /** The workspace's ID counter — a draft must not advance it (DR-04). */
    private function sequence(string $workspaceId): int
    {
        return (int) DB::table('tenants')->where('id', $workspaceId)->value('work_item_sequence');
    }

    private function draft(array $overrides = []): array
    {
        return array_merge(['title' => 'Chase the invoice PDF bug'], $overrides);
    }

    public function test_the_drafts_screen_renders_and_the_sidebar_links_to_it(): void
    {
        [$owner] = $this->owner();

        // DR-01.
        $this->actingAs($owner)->get(route('drafts.index'))
            ->assertOk()
            ->assertSee('Drafts', false)
            ->assertSee(route('drafts.index'), false);
    }

    public function test_the_screen_uses_the_shared_pickers_rather_than_its_own(): void
    {
        [$owner] = $this->owner();

        $html = $this->actingAs($owner)->get(route('drafts.index'))->assertOk()->getContent();

        // Pinned because the whole point of these is that they are NOT reimplemented here.
        // Drop one and the screen still works while quietly looking like a different product.
        $shared = [
            'assets/js/settings/app.js' => '<pb-combo>, the searchable pickers',
            'assets/js/projects/work-item-ui.js' => 'WI_PRI and wiStateIcon, the priority and state glyphs',
            'assets/js/projects/date-picker.js' => '<wi-calendar>, the date popover',
            'assets/js/projects/page-editor.js' => '<pg-editor>, the description editor',
            'assets/vendor/jodit/jodit.fat.min.js' => 'Jodit, which <pg-editor> mounts onto',
            'assets/js/projects/work-items.js' => '<wi-editor>, the fallback description editor',
            'assets/vendor/quill/quill.js' => 'Quill, which <wi-editor> mounts onto',
        ];

        foreach ($shared as $asset => $what) {
            $this->assertStringContainsString($asset, $html, "The drafts screen stopped loading {$what}.");
        }
    }

    public function test_the_description_is_rich_text_and_is_sanitized(): void
    {
        [$owner] = $this->owner();

        // <wi-editor> posts HTML, so the draft goes through the same sanitizer a work item's
        // description does — formatting survives, script does not, and an editor left empty
        // stores nothing rather than its own scaffolding.
        $this->actingAs($owner)->postJson(route('drafts.store'), $this->draft([
            'description' => '<p>The totals row <strong>overflows</strong>.</p><script>alert(1)</script>',
        ]))->assertCreated();

        $draft = WorkItem::drafts($owner->id)->firstOrFail();
        $this->assertStringContainsString('<strong>overflows</strong>', $draft->description);
        $this->assertStringNotContainsString('<script', $draft->description);

        $this->actingAs($owner)->patchJson(route('drafts.update', $draft), ['description' => '<p><br></p>'])
            ->assertOk();
        $this->assertNull($draft->fresh()->description);
    }

    public function test_an_image_can_be_uploaded_from_a_draft_and_is_private_to_its_uploader(): void
    {
        Storage::fake('local');
        [$owner, $ws] = $this->owner();
        $other = $this->member($ws, 'admin', 'admin@example.com');

        $response = $this->actingAs($owner)
            ->postJson(route('drafts.media.store'), ['file-0' => UploadedFile::fake()->image('screenshot.png')])
            ->assertOk();

        // The editor's contract, the same one the project endpoint answers.
        $url = $response->json('result.0.url');
        $this->assertNotEmpty($url);

        $media = WorkItemMedia::firstOrFail();
        // Project-less, exactly as the draft it belongs to is.
        $this->assertNull($media->project_id);
        $this->assertSame($owner->id, $media->uploaded_by);
        // Private disk, never public: these are images from somebody's private scratch pad.
        Storage::disk('local')->assertExists($media->path);

        $this->actingAs($owner)->get($url)->assertOk();
        // Not even a workspace Admin, while it is still a draft's image.
        $this->actingAs($other)->get($url)->assertNotFound();
        // And the gallery shows one author their own uploads only.
        $this->actingAs($other)->getJson(route('drafts.media.index'))->assertOk()->assertJsonCount(0, 'result');
        $this->actingAs($owner)->getJson(route('drafts.media.index'))->assertOk()->assertJsonCount(1, 'result');
    }

    public function test_publishing_re_homes_the_images_the_description_uses(): void
    {
        Storage::fake('local');
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $reader = $this->member($ws, 'admin', 'reader@example.com');

        $used = $this->actingAs($owner)->postJson(route('drafts.media.store'), [
            'file-0' => UploadedFile::fake()->image('used.png'),
        ])->json('result.0.url');

        $this->actingAs($owner)->postJson(route('drafts.media.store'), [
            'file-0' => UploadedFile::fake()->image('removed.png'),
        ])->assertOk();

        $this->actingAs($owner)->postJson(route('drafts.store'), $this->draft([
            'description' => '<p>Looks like this: <img src="'.$used.'" alt="used" /></p>',
        ]))->assertCreated();
        $draft = WorkItem::drafts($owner->id)->firstOrFail();

        // A colleague cannot read it yet — it is still a private draft's image.
        $this->actingAs($reader)->get($used)->assertNotFound();

        $this->actingAs($owner)->postJson(route('drafts.publish', $draft), ['project_id' => $project->id])
            ->assertOk();

        // Published: the image is part of a work item other people read, so they can see it —
        // through the URL already baked into the description, which is never rewritten.
        $this->actingAs($reader)->get($used)->assertOk();

        [$usedMedia, $removedMedia] = WorkItemMedia::orderBy('id')->get()->all();
        $this->assertSame($project->id, $usedMedia->project_id);
        $this->assertSame($draft->id, $usedMedia->work_item_id);
        // The one the description never referenced stays behind: an image inserted and then
        // deleted again should not follow the item into a project it never appeared in.
        $this->assertNull($removedMedia->project_id);
        $this->actingAs($reader)->get($removedMedia->url())->assertNotFound();
    }

    public function test_a_crafted_description_cannot_annex_another_users_draft_image(): void
    {
        Storage::fake('local');
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        // A workspace Admin, so they may genuinely publish into this project — the attempt
        // has to fail on the media rule, not on being refused the publish outright.
        $thief = $this->member($ws, 'admin', 'thief@example.com');

        $victimUrl = $this->actingAs($owner)->postJson(route('drafts.media.store'), [
            'file-0' => UploadedFile::fake()->image('private.png'),
        ])->json('result.0.url');

        // Someone else quotes that image id in their own draft and publishes it, hoping the
        // re-home hands them a project-readable copy.
        $this->actingAs($thief)->postJson(route('drafts.store'), $this->draft([
            'description' => '<p><img src="'.$victimUrl.'" alt="not mine" /></p>',
        ]))->assertCreated();
        $theirs = WorkItem::drafts($thief->id)->firstOrFail();

        $this->actingAs($thief)->postJson(route('drafts.publish', $theirs), ['project_id' => $project->id])
            ->assertOk();

        // The re-home is scoped to the publisher's own uploads, so the image never moved.
        $media = WorkItemMedia::firstOrFail();
        $this->assertNull($media->project_id);
        $this->actingAs($thief)->get($victimUrl)->assertNotFound();
    }

    public function test_viewers_cannot_upload_draft_images(): void
    {
        Storage::fake('local');
        [, $ws] = $this->owner();
        $viewer = $this->member($ws, 'viewer', 'viewer-media@example.com');

        $this->actingAs($viewer)
            ->postJson(route('drafts.media.store'), ['file-0' => UploadedFile::fake()->image('x.png')])
            ->assertForbidden();

        $this->assertSame(0, WorkItemMedia::count());
    }

    public function test_publishing_without_a_starting_state_is_allowed(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $before = $this->sequence($ws->id);

        $this->actingAs($owner)->postJson(route('drafts.store'), $this->draft())->assertCreated();
        $draft = WorkItem::drafts($owner->id)->firstOrFail();

        // The state picker is hidden when the project has none configured, so the modal posts
        // an empty string — which must read as "no state", not as a validation failure.
        $this->actingAs($owner)->postJson(route('drafts.publish', $draft), [
            'project_id' => $project->id,
            'state_id' => '',
        ])->assertOk();

        $item = WorkItem::findOrFail($draft->id);
        $this->assertFalse($item->isDraft());
        $this->assertNull($item->state_id);
        $this->assertSame($before + 1, $item->sequence_no);
    }

    public function test_viewers_and_guests_have_no_drafts(): void
    {
        [$owner, $ws] = $this->owner();
        $this->makeProject($owner, $ws);

        // DR-02: they cannot create work items anywhere (§7), so a draft would be a note they
        // could never publish — the screen 404s and the sidebar link is absent.
        foreach (['viewer', 'guest'] as $role) {
            $user = $this->member($ws, $role, "{$role}@example.com");

            $this->actingAs($user)->get(route('drafts.index'))->assertNotFound();
            $this->actingAs($user)->post(route('drafts.store'), $this->draft())->assertForbidden();
            $this->actingAs($user)->get(route('projects.index'))
                ->assertOk()
                ->assertDontSee(route('drafts.index'), false);
        }
    }

    public function test_a_draft_is_created_with_no_project_state_or_id_number(): void
    {
        [$owner, $ws] = $this->owner();
        $before = $this->sequence($ws->id);

        $response = $this->actingAs($owner)
            ->postJson(route('drafts.store'), $this->draft(['description' => 'The totals row overflows.']))
            ->assertCreated();

        // DR-03: the three columns a work item cannot be without are all NULL on a draft.
        $draft = WorkItem::drafts($owner->id)->firstOrFail();
        $this->assertTrue($draft->isDraft());
        $this->assertNull($draft->project_id);
        $this->assertNull($draft->sequence_no);
        $this->assertNull($draft->identifier);
        $this->assertNull($draft->state_id);
        $this->assertSame($owner->id, $draft->created_by);
        $this->assertSame($draft->id, $response->json('draft.id'));

        // DR-04: capturing a thought must not burn an ID it may never use.
        $this->assertSame($before, $this->sequence($ws->id));
    }

    public function test_a_draft_ignores_work_item_fields_it_cannot_carry(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        // Opening Drafts provisions each publishable project's states, so the publish modal's
        // picker is never empty on a project whose Work Items screen has not been opened yet.
        $this->actingAs($owner)->get(route('drafts.index'))->assertOk();
        // ProjectItemState is tenant-scoped, and nothing else has initialized tenancy here.
        $state = $ws->run(fn () => ProjectItemState::where('project_id', $project->id)->firstOrFail());

        // DR-14: state, assignees and the rest are project-scoped and a draft has no project
        // to validate them against (D-D3), so a payload carrying them writes nothing.
        $this->actingAs($owner)->postJson(route('drafts.store'), $this->draft([
            'project_id' => $project->id,
            'state_id' => $state->id,
            'assignee_ids' => [$owner->id],
            'is_draft' => false,
        ]))->assertCreated();

        $draft = WorkItem::drafts($owner->id)->firstOrFail();
        $this->assertNull($draft->project_id);
        $this->assertNull($draft->state_id);
        $this->assertTrue($draft->isDraft());
        $this->assertCount(0, $draft->assignees);
    }

    public function test_drafts_are_invisible_to_every_work_item_query(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);

        $this->actingAs($owner)->postJson(route('drafts.store'), $this->draft())->assertCreated();

        // DR-05: the global scope, not a filter each caller remembers (D-D2).
        $this->assertSame(0, WorkItem::count());
        $this->assertSame(0, WorkItem::forProject($project->id)->count());
        $this->assertSame(1, WorkItem::drafts($owner->id)->count());

        $this->actingAs($owner)->get(route('projects.work-items', $project))
            ->assertOk()
            ->assertDontSee('Chase the invoice PDF bug', false);
    }

    public function test_a_draft_cannot_be_reached_through_the_project_work_item_routes(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);

        $this->actingAs($owner)->postJson(route('drafts.store'), $this->draft())->assertCreated();
        $draft = WorkItem::drafts($owner->id)->firstOrFail();

        // DR-07: implicit binding goes through the global scope, so the item is simply absent.
        $args = ['project' => $project->id, 'workItem' => $draft->id];
        $this->actingAs($owner)->get(route('projects.work-items.show', $args))->assertNotFound();
        $this->actingAs($owner)->patchJson(route('projects.work-items.update', $args), ['title' => 'x'])->assertNotFound();
        $this->actingAs($owner)->postJson(route('projects.work-items.archive', $args))->assertNotFound();
    }

    public function test_drafts_are_private_to_their_author(): void
    {
        [$owner, $ws] = $this->owner();
        $other = $this->member($ws, 'admin', 'admin@example.com');

        $this->actingAs($owner)->postJson(route('drafts.store'), $this->draft())->assertCreated();
        $draft = WorkItem::drafts($owner->id)->firstOrFail();

        // DR-06: not even a workspace Admin reads somebody else's scratch pad, and the refusal
        // is a 404 that never confirms the draft exists.
        $this->actingAs($other)->get(route('drafts.index'))
            ->assertOk()
            ->assertDontSee('Chase the invoice PDF bug', false);

        $this->actingAs($other)->patchJson(route('drafts.update', $draft), ['title' => 'Mine now'])->assertNotFound();
        $this->actingAs($other)->deleteJson(route('drafts.destroy', $draft))->assertNotFound();
        $this->actingAs($other)->postJson(route('drafts.publish', $draft), ['project_id' => 1])->assertNotFound();

        // `fresh()`/`refresh()` re-query without global scopes, so a draft is still readable
        // through the model it was loaded into — the isolation is in the query, not the row.
        $this->assertSame('Chase the invoice PDF bug', $draft->fresh()->title);
    }

    public function test_a_draft_can_be_edited(): void
    {
        [$owner] = $this->owner();

        $this->actingAs($owner)->postJson(route('drafts.store'), $this->draft())->assertCreated();
        $draft = WorkItem::drafts($owner->id)->firstOrFail();

        // DR-13.
        $this->actingAs($owner)->patchJson(route('drafts.update', $draft), [
            'title' => 'Chase the invoice PDF bug (blocking release)',
            'priority' => 'urgent',
            'due_date' => '2026-12-01',
        ])->assertOk();

        $draft->refresh();
        $this->assertSame('Chase the invoice PDF bug (blocking release)', $draft->title);
        $this->assertSame('urgent', $draft->priority);
        $this->assertSame('2026-12-01', $draft->due_date->toDateString());
    }

    public function test_publishing_turns_a_draft_into_a_work_item(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        // Opening Drafts provisions each publishable project's states, so the publish modal's
        // picker is never empty on a project whose Work Items screen has not been opened yet.
        $this->actingAs($owner)->get(route('drafts.index'))->assertOk();
        // ProjectItemState is tenant-scoped, and nothing else has initialized tenancy here.
        $state = $ws->run(fn () => ProjectItemState::where('project_id', $project->id)->firstOrFail());
        $before = $this->sequence($ws->id);

        $this->actingAs($owner)->postJson(route('drafts.store'), $this->draft())->assertCreated();
        $draft = WorkItem::drafts($owner->id)->firstOrFail();

        $this->actingAs($owner)->postJson(route('drafts.publish', $draft), [
            'project_id' => $project->id,
            'state_id' => $state->id,
        ])->assertOk();

        // DR-08: it is an ordinary work item now, with the next workspace ID number.
        $item = WorkItem::findOrFail($draft->id);
        $this->assertFalse($item->isDraft());
        $this->assertSame($project->id, $item->project_id);
        $this->assertSame($before + 1, $item->sequence_no);
        $this->assertSame((string) ($before + 1), $item->identifier);
        $this->assertSame($state->id, $item->state_id);
        // §6: the person who wrote the draft is the creator.
        $this->assertSame($owner->id, $item->created_by);
        $this->assertSame($before + 1, $this->sequence($ws->id));

        // DR-09: its recorded history begins the moment it became real.
        $this->assertSame(1, WorkItemActivity::where('work_item_id', $item->id)
            ->where('event', WorkItemActivity::EVENT_CREATED)->count());
        $this->assertSame(1, WorkItemTransition::where('work_item_id', $item->id)->count());

        // DR-11: and it has left the drafts list.
        $this->assertSame(0, WorkItem::drafts($owner->id)->count());
        $this->actingAs($owner)->get(route('projects.work-items', $project))
            ->assertOk()
            ->assertSee('Chase the invoice PDF bug', false);
    }

    public function test_publishing_into_a_project_the_user_cannot_add_work_to_is_refused(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $member = $this->member($ws, 'member', 'member@example.com');
        $before = $this->sequence($ws->id);

        $this->actingAs($member)->postJson(route('drafts.store'), $this->draft())->assertCreated();
        $draft = WorkItem::drafts($member->id)->firstOrFail();

        // DR-10: publishing is not a way around WorkItemPolicy@create — this member was never
        // added to the project.
        $this->actingAs($member)->postJson(route('drafts.publish', $draft), [
            'project_id' => $project->id,
        ])->assertForbidden();

        $draft->refresh();
        $this->assertTrue($draft->isDraft());
        $this->assertNull($draft->project_id);
        $this->assertSame($before, $this->sequence($ws->id));
    }

    public function test_a_draft_can_be_discarded(): void
    {
        [$owner] = $this->owner();

        $this->actingAs($owner)->postJson(route('drafts.store'), $this->draft())->assertCreated();
        $draft = WorkItem::drafts($owner->id)->firstOrFail();

        // DR-12: a hard delete — there is no history to preserve, because drafts are not audited.
        $this->actingAs($owner)->deleteJson(route('drafts.destroy', $draft))->assertOk();

        $this->assertDatabaseMissing('work_items', ['id' => $draft->id]);
        $this->assertSame(0, WorkItem::drafts($owner->id)->count());
    }

    public function test_a_draft_needs_a_title(): void
    {
        [$owner] = $this->owner();

        $this->actingAs($owner)->postJson(route('drafts.store'), ['title' => '   '])
            ->assertStatus(422)
            ->assertJsonValidationErrors('title');

        $this->assertSame(0, WorkItem::drafts($owner->id)->count());
    }

    public function test_a_partial_edit_still_keeps_the_start_date_before_the_due_date(): void
    {
        [$owner] = $this->owner();

        $this->actingAs($owner)->postJson(route('drafts.store'), $this->draft([
            'start_date' => '2026-11-10',
            'due_date' => '2026-11-20',
        ]))->assertCreated();
        $draft = WorkItem::drafts($owner->id)->firstOrFail();

        // The editor saves one control at a time, so this PATCH carries no start date for
        // `after:start_date` to read — the stored one is used instead (UpdateDraftRequest).
        $this->actingAs($owner)->patchJson(route('drafts.update', $draft), ['due_date' => '2026-11-01'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('due_date');

        $this->assertSame('2026-11-20', $draft->fresh()->due_date->toDateString());
    }
}
