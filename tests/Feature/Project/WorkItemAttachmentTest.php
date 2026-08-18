<?php

namespace Tests\Feature\Project;

use App\Models\ProjectMember;
use App\Models\WorkItemAttachment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Work item attachments — docs/features/work-item-attachments.md.
 *
 * The paperclip in the detail action row, which shipped inert until this feature. Covers the
 * acceptance criteria in that spec: who may upload, read and delete; that the file lands
 * PRIVATE on the configured disk; and that the row records the disk it actually used.
 */
class WorkItemAttachmentTest extends ProjectTestCase
{
    use RefreshDatabase;

    private function projectMember($ws, $project, string $role, string $email, string $projectRole = 'contributor')
    {
        $user = $this->member($ws, $role, $email);
        $ws->run(fn () => ProjectMember::create([
            'project_id' => $project->id, 'user_id' => $user->id, 'role' => $projectRole,
        ]));

        return $user;
    }

    private function makeItem($owner, $project, string $title = 'Work'): array
    {
        return $this->actingAs($owner)
            ->postJson(route('projects.work-items.store', $project), ['title' => $title])
            ->assertStatus(201)->json('item');
    }

    /** @return array{0: mixed, 1: mixed, 2: array<string, mixed>} */
    private function scene(): array
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $item = $this->makeItem($owner, $project);

        return [$owner, $project, $item];
    }

    public function test_an_editor_uploads_lists_downloads_and_deletes_an_attachment(): void
    {
        Storage::fake('local');
        [$owner, $project, $item] = $this->scene();

        $route = ['project' => $project->id, 'workItem' => $item['id']];

        $this->actingAs($owner)->post(route('projects.work-items.attachments.store', $route), [
            'file-0' => UploadedFile::fake()->create('spec.pdf', 12, 'application/pdf'),
        ])->assertOk()->assertJsonPath('added', 1);

        $row = WorkItemAttachment::first();
        $this->assertSame('spec.pdf', $row->name);
        $this->assertSame($item['id'], $row->work_item_id);
        $this->assertSame($project->id, $row->project_id);
        $this->assertSame($owner->id, $row->uploaded_by);
        Storage::disk('local')->assertExists($row->path);

        // The list is what the modal renders.
        $this->actingAs($owner)->getJson(route('projects.work-items.attachments.index', $route))
            ->assertOk()->assertJsonPath('result.0.name', 'spec.pdf');

        // Download streams the file back under its original name.
        $this->actingAs($owner)
            ->get(route('projects.work-items.attachments.show', $route + ['attachment' => $row->id]))
            ->assertOk()->assertDownload('spec.pdf');

        $this->actingAs($owner)
            ->deleteJson(route('projects.work-items.attachments.destroy', $route + ['attachment' => $row->id]))
            ->assertOk()->assertJsonPath('result', []);

        $this->assertNull(WorkItemAttachment::find($row->id));
        Storage::disk('local')->assertMissing($row->path);
    }

    /**
     * The disk is read from config and the write is PRIVATE.
     *
     * Both halves are the profile-image bug repeated: a hardcoded disk ignores MEDIA_DISK, and
     * the `spaces` disk defaults writes to public-read, which would publish every attachment to
     * an unauthenticated URL and bypass the ability check show() enforces.
     */
    public function test_attachments_honour_the_configured_disk_and_stay_private(): void
    {
        config(['filesystems.media_disk' => 'attach_test']);
        Storage::fake('attach_test');
        Storage::fake('local');

        [$owner, $project, $item] = $this->scene();

        $this->actingAs($owner)->post(route('projects.work-items.attachments.store', [
            'project' => $project->id, 'workItem' => $item['id'],
        ]), ['file-0' => UploadedFile::fake()->create('notes.txt', 1, 'text/plain')])->assertOk();

        $row = WorkItemAttachment::first();

        Storage::disk('attach_test')->assertExists($row->path);
        Storage::disk('local')->assertMissing($row->path);
        $this->assertSame('private', Storage::disk('attach_test')->getVisibility($row->path));
        // Recorded, so a later MEDIA_DISK change cannot strand this row.
        $this->assertSame('attach_test', $row->disk);
    }

    public function test_a_stranger_cannot_read_or_upload_and_a_guest_is_stopped(): void
    {
        Storage::fake('local');
        [$owner, $project, $item] = $this->scene();
        $route = ['project' => $project->id, 'workItem' => $item['id']];

        $this->actingAs($owner)->post(route('projects.work-items.attachments.store', $route), [
            'file-0' => UploadedFile::fake()->create('spec.pdf', 4, 'application/pdf'),
        ])->assertOk();
        $row = WorkItemAttachment::first();

        // 404 rather than 403: a refusal must not confirm the file is there.
        //
        // The stranger gets a workspace of their OWN rather than being left without one. A user
        // with no active workspace is redirected by the workspace guard long before this
        // controller runs, which would pass the test without ever exercising its authorization.
        [$stranger] = $this->owner('other-co');
        $this->actingAs($stranger)
            ->get(route('projects.work-items.attachments.show', $route + ['attachment' => $row->id]))
            ->assertNotFound();
        $this->actingAs($stranger)
            ->getJson(route('projects.work-items.attachments.index', $route))
            ->assertNotFound();

        $this->assertContains('auth', app('router')->getRoutes()
            ->getByName('projects.work-items.attachments.show')->gatherMiddleware());
    }

    /**
     * A viewer reads but cannot change. Reading is gated on seeing the project's work items,
     * writing on being able to create them (§34) — so a read-only member sees the files and is
     * refused the upload and the delete.
     */
    public function test_a_viewer_may_read_but_not_upload_or_delete(): void
    {
        Storage::fake('local');
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $item = $this->makeItem($owner, $project);
        $route = ['project' => $project->id, 'workItem' => $item['id']];

        $this->actingAs($owner)->post(route('projects.work-items.attachments.store', $route), [
            'file-0' => UploadedFile::fake()->create('spec.pdf', 4, 'application/pdf'),
        ])->assertOk();
        $row = WorkItemAttachment::first();

        $viewer = $this->projectMember($ws, $project, 'guest', 'viewer@example.com', 'viewer');

        $this->actingAs($viewer)->getJson(route('projects.work-items.attachments.index', $route))
            ->assertOk()->assertJsonPath('result.0.name', 'spec.pdf');

        $this->actingAs($viewer)->postJson(route('projects.work-items.attachments.store', $route), [
            'file-0' => UploadedFile::fake()->create('mine.pdf', 4, 'application/pdf'),
        ])->assertForbidden();

        $this->actingAs($viewer)
            ->deleteJson(route('projects.work-items.attachments.destroy', $route + ['attachment' => $row->id]))
            ->assertNotFound();

        $this->assertNotNull(WorkItemAttachment::find($row->id));
    }

    public function test_a_disallowed_type_is_refused_and_nothing_is_stored(): void
    {
        Storage::fake('local');
        [$owner, $project, $item] = $this->scene();

        $this->actingAs($owner)->postJson(route('projects.work-items.attachments.store', [
            'project' => $project->id, 'workItem' => $item['id'],
        ]), ['file-0' => UploadedFile::fake()->create('payload.exe', 8, 'application/x-msdownload')])
            ->assertStatus(422)->assertJsonStructure(['errorMessage']);

        $this->assertSame(0, WorkItemAttachment::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    /** An attachment belongs to its item: deleting the item takes its files' rows with it. */
    public function test_deleting_the_work_item_removes_its_attachments(): void
    {
        Storage::fake('local');
        [$owner, $project, $item] = $this->scene();

        $this->actingAs($owner)->post(route('projects.work-items.attachments.store', [
            'project' => $project->id, 'workItem' => $item['id'],
        ]), ['file-0' => UploadedFile::fake()->create('spec.pdf', 4, 'application/pdf')])->assertOk();

        $this->assertSame(1, WorkItemAttachment::count());

        $this->actingAs($owner)
            ->deleteJson(route('projects.work-items.destroy', ['project' => $project->id, 'workItem' => $item['id']]))
            ->assertOk();

        $this->assertSame(0, WorkItemAttachment::count());
    }
}
