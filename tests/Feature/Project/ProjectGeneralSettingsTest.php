<?php

namespace Tests\Feature\Project;

use App\Models\Project;
use App\Models\ProjectMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Project Settings → General.
 * Source: ProjectBlock 3.0 — Project Settings > General Requirements.
 */
class ProjectGeneralSettingsTest extends ProjectTestCase
{
    use RefreshDatabase;

    /** The cover form posts multipart but asks for JSON back, exactly as the screen does. */
    private const JSON = ['Accept' => 'application/json'];

    /** The full settings payload, so a save never blanks a field it did not mean to touch. */
    private function payload(Project $project, array $overrides = []): array
    {
        return array_merge([
            'name' => $project->name,
            'identifier' => $project->identifier,
            'description' => $project->description,
            'visibility' => $project->visibility,
            'lead_user_id' => $project->lead_user_id,
            'timezone' => $project->timezone ?: 'UTC',
        ], $overrides);
    }

    public function test_work_item_view_default_assignee_and_subscribers_persist(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'WEB']);
        $sarah = $this->member($ws, 'member', 'sarah@example.com');
        $james = $this->member($ws, 'member', 'james@example.com');

        $this->actingAs($owner)->patchJson(
            route('projects.settings.general.update', $project),
            $this->payload($project, [
                'work_item_view' => 'assigned',
                'default_assignee_id' => $sarah->id,
                'subscriber_ids' => [$sarah->id, $james->id],
            ]),
        )->assertOk()->assertJsonPath('message', 'Project settings updated successfully.');

        $fresh = $ws->run(fn () => Project::find($project->id));
        $this->assertSame('assigned', $fresh->work_item_view);
        $this->assertSame($sarah->id, $fresh->default_assignee_id);
        $this->assertEqualsCanonicalizing([$sarah->id, $james->id], $fresh->subscribers()->pluck('users.id')->all());

        // §13: the form sends the whole list, so a removed subscriber has to disappear.
        $this->actingAs($owner)->patchJson(
            route('projects.settings.general.update', $project),
            $this->payload($project, ['subscriber_ids' => [$james->id]]),
        )->assertOk();
        $this->assertSame([$james->id], $ws->run(fn () => Project::find($project->id))->subscribers()->pluck('users.id')->all());
    }

    public function test_settings_only_accept_members_of_this_workspace(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'WEB']);
        [$outsider] = $this->owner('other-co');

        // §21-style isolation: a settings save cannot attach someone from another tenant.
        $this->actingAs($owner)->patchJson(
            route('projects.settings.general.update', $project),
            $this->payload($project, ['default_assignee_id' => $outsider->id]),
        )->assertStatus(422)->assertJsonValidationErrors('default_assignee_id');

        $this->actingAs($owner)->patchJson(
            route('projects.settings.general.update', $project),
            $this->payload($project, ['subscriber_ids' => [$outsider->id]]),
        )->assertStatus(422)->assertJsonValidationErrors('subscriber_ids.0');

        $this->actingAs($owner)->patchJson(
            route('projects.settings.general.update', $project),
            $this->payload($project, ['work_item_view' => 'whatever']),
        )->assertStatus(422)->assertJsonValidationErrors('work_item_view');
    }

    public function test_the_default_assignee_is_applied_only_when_none_is_chosen(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'WEB']);
        $this->actingAs($owner)->get(route('projects.work-items', $project));

        $sarah = $this->member($ws, 'member', 'sarah@example.com');
        $james = $this->member($ws, 'member', 'james@example.com');

        $this->actingAs($owner)->patchJson(
            route('projects.settings.general.update', $project),
            $this->payload($project, ['default_assignee_id' => $sarah->id]),
        )->assertOk();

        // §12: nothing chosen — the default applies.
        $auto = $this->actingAs($owner)->postJson(route('projects.work-items.store', $project), [
            'title' => 'Update homepage banner',
        ])->assertStatus(201)->json('item');
        $this->assertSame([$sarah->id], array_column($auto['assignees'], 'id'));

        // §12: a manual choice overrides it.
        $manual = $this->actingAs($owner)->postJson(route('projects.work-items.store', $project), [
            'title' => 'Chosen', 'assignee_ids' => [$james->id],
        ])->assertStatus(201)->json('item');
        $this->assertSame([$james->id], array_column($manual['assignees'], 'id'));
    }

    public function test_assigned_only_hides_other_members_work_items_everywhere(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'WEB']);
        $this->actingAs($owner)->get(route('projects.work-items', $project));

        $sarah = $this->projectMember($ws, $project, 'sarah@example.com');
        $james = $this->projectMember($ws, $project, 'james@example.com');

        $hers = $this->itemAssignedTo($owner, $project, 'WEB-1 for Sarah', $sarah->id);
        $his = $this->itemAssignedTo($owner, $project, 'WEB-3 for James', $james->id);

        $this->actingAs($owner)->patchJson(
            route('projects.settings.general.update', $project),
            $this->payload($project, ['work_item_view' => 'assigned']),
        )->assertOk();

        // §24: Sarah sees only her own.
        $listed = $this->actingAs($sarah)->get(route('projects.work-items', $project))
            ->assertOk()->viewData('bootstrap')['items'];
        $this->assertSame([$hers['id']], array_column($listed, 'id'));

        // §10 Security Requirement: not merely hidden — the direct URL is refused too, with
        // 404 rather than 403 so the response does not confirm the item exists.
        $this->actingAs($sarah)->get(route('projects.work-items.show', [
            'project' => $project->id, 'workItem' => $his['id'],
        ]))->assertNotFound();

        $this->actingAs($sarah)->getJson(route('projects.work-items.feed', [
            'project' => $project->id, 'workItem' => $his['id'],
        ]))->assertNotFound();

        // …and the relation picker cannot be used to read the titles it hides.
        $found = $this->actingAs($sarah)->getJson(route('projects.work-items.search', [
            'project' => $project->id, 'workItem' => $hers['id'],
        ]))->assertOk()->json('items');
        $this->assertSame([], array_column($found, 'id'));
    }

    public function test_privileged_roles_keep_sight_of_every_work_item(): void
    {
        [$owner, $ws] = $this->owner();
        $lead = $this->projectMember($ws, null, 'lead@example.com');
        $project = $this->makeProject($owner, $ws, ['identifier' => 'WEB', 'lead_user_id' => $lead->id]);
        $this->actingAs($owner)->get(route('projects.work-items', $project));

        $sarah = $this->projectMember($ws, $project, 'sarah@example.com');
        $hers = $this->itemAssignedTo($owner, $project, 'Only Sarah has this', $sarah->id);

        $this->actingAs($owner)->patchJson(
            route('projects.settings.general.update', $project),
            $this->payload($project, ['work_item_view' => 'assigned']),
        )->assertOk();

        // §10: the workspace owner and the project lead administer the project, so the
        // setting must not blind them to it.
        foreach ([$owner, $lead] as $privileged) {
            $items = $this->actingAs($privileged)->get(route('projects.work-items', $project))
                ->assertOk()->viewData('bootstrap')['items'];
            $this->assertContains($hers['id'], array_column($items, 'id'));
        }
    }

    public function test_the_settings_screen_reports_the_creation_date_and_current_values(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'WEB']);

        $this->actingAs($owner)->get(route('projects.settings', ['project' => $project->id, 'section' => 'general']))
            ->assertOk()
            ->assertViewHas('bootstrap', function ($b) use ($project) {
                return $b['project']['work_item_view'] === 'all'
                    && $b['project']['subscriber_ids'] === []
                    // §17: read-only, from the original creation timestamp.
                    && $b['project']['created_on'] === $project->created_at->format('M j, Y')
                    && count($b['workItemViews']) === 2;
            });
    }

    public function test_the_project_id_cannot_be_changed_after_creation(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'WEB']);

        // Sending the current value is fine — the form does exactly that.
        $this->actingAs($owner)->patchJson(
            route('projects.settings.general.update', $project),
            $this->payload($project, ['name' => 'Renamed']),
        )->assertOk();

        // A different one is refused rather than silently ignored: the ID is baked into every
        // work item identifier and every link already shared.
        $this->actingAs($owner)->patchJson(
            route('projects.settings.general.update', $project),
            $this->payload($project, ['identifier' => 'NEWID']),
        )->assertStatus(422)->assertJsonValidationErrors('identifier');

        $fresh = $ws->run(fn () => Project::find($project->id));
        $this->assertSame('web', $fresh->identifier);
        $this->assertSame('Renamed', $fresh->name);
    }

    // ================= §4: Change cover =================

    public function test_the_cover_upload_stores_the_file_and_persists_a_reachable_url(): void
    {
        Storage::fake('public');

        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);

        $url = $this->actingAs($owner)->post(route('projects.settings.cover', $project), [
            'cover' => UploadedFile::fake()->image('banner.jpg', 1200, 400),
        ], self::JSON)->assertOk()->json('cover_url');

        // The file lands on the PUBLIC disk, which is the half that matters: it is served
        // through the public/storage symlink, and without that link the upload succeeds and
        // the image 404s — indistinguishable from "the upload is broken".
        $this->assertNotEmpty(Storage::disk('public')->files("project-covers/{$ws->id}"));
        $this->assertStringContainsString('/storage/', $url);
        $this->assertSame($url, $ws->run(fn () => Project::find($project->id))->cover_url);
    }

    public function test_the_cover_upload_refuses_what_it_should(): void
    {
        Storage::fake('public');

        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);

        // Not an image at all.
        $this->actingAs($owner)->post(route('projects.settings.cover', $project), [
            'cover' => UploadedFile::fake()->create('notes.pdf', 40, 'application/pdf'),
        ], self::JSON)->assertStatus(422)->assertJsonValidationErrors('cover');

        // Over the configured size cap.
        $tooBig = (int) config('projects.cover.max_kb') + 512;
        $this->actingAs($owner)->post(route('projects.settings.cover', $project), [
            'cover' => UploadedFile::fake()->create('huge.jpg', $tooBig, 'image/jpeg'),
        ], self::JSON)->assertStatus(422)->assertJsonValidationErrors('cover');

        // §3: a contributor may look at settings but not restyle the project.
        $contributor = $this->projectMember($ws, $project, 'contributor-cover@example.com');
        $this->actingAs($contributor)->post(route('projects.settings.cover', $project), [
            'cover' => UploadedFile::fake()->image('banner.jpg'),
        ], self::JSON)->assertStatus(403);
    }

    public function test_only_authorized_users_can_change_general_settings(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'WEB']);
        $contributor = $this->projectMember($ws, $project, 'contributor@example.com');

        // §3: contributors may look but not edit.
        $this->actingAs($contributor)->patchJson(
            route('projects.settings.general.update', $project),
            $this->payload($project, ['name' => 'Renamed by a contributor']),
        )->assertStatus(403);

        $this->assertSame($project->name, $ws->run(fn () => Project::find($project->id))->name);
    }

    /** A workspace member, optionally added to the project as a contributor. */
    private function projectMember($ws, ?Project $project, string $email)
    {
        $user = $this->member($ws, 'member', $email);

        if ($project) {
            $ws->run(fn () => ProjectMember::create([
                'project_id' => $project->id, 'user_id' => $user->id, 'role' => 'contributor',
            ]));
        }

        return $user;
    }

    /** @return array<string, mixed> */
    private function itemAssignedTo($owner, Project $project, string $title, int $userId): array
    {
        return $this->actingAs($owner)->postJson(route('projects.work-items.store', $project), [
            'title' => $title, 'assignee_ids' => [$userId],
        ])->assertStatus(201)->json('item');
    }
}
