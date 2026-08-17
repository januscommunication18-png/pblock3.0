<?php

namespace Tests\Feature\Wiki;

use App\Models\User;
use App\Models\WikiCollection;
use App\Models\WikiCollectionMember;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\WorkspaceApps;
use App\Services\WorkspaceCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Wiki's three lenses — Shared, Private and Archived (docs/features/wiki.md).
 *
 * A lens, never a move: a private collection listed under Private is still under Collections.
 * And every one of them is permission-filtered, because a row somebody cannot open must not be
 * named to them either.
 */
class SectionsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    private function setUpWiki(): void
    {
        $this->owner = User::factory()->create(['full_name' => 'Rohit', 'email' => 'owner@example.com']);

        $this->workspace = app(WorkspaceCreator::class)->create($this->owner, [
            'name' => 'Acme Inc', 'slug' => 'acme-inc', 'company_size' => '2-10',
            'apps' => ['projects', 'wiki'],
        ]);

        $this->owner = $this->owner->fresh();
        tenancy()->initialize($this->workspace);
    }

    private function mate(string $email = 'sarah@example.com', string $role = 'member'): User
    {
        $user = User::factory()->create(['full_name' => 'Sarah Johnson', 'email' => $email]);

        WorkspaceMembership::create([
            'workspace_id' => $this->workspace->id, 'user_id' => $user->id,
            'role' => $role, 'status' => 'active', 'joined_at' => now(),
        ]);
        $user->forceFill(['current_workspace_id' => $this->workspace->id])->save();

        return $user->fresh();
    }

    private function collection(array $overrides = []): WikiCollection
    {
        return WikiCollection::create(array_merge([
            'tenant_id' => $this->workspace->id,
            'name' => 'Help Desk Software',
            'visibility' => 'private',
            'created_by' => $this->owner->id,
            'position' => 1,
        ], $overrides));
    }

    private function invite(WikiCollection $collection, User $user, string $permission = 'read'): void
    {
        WikiCollectionMember::create([
            'tenant_id' => $this->workspace->id,
            'wiki_collection_id' => $collection->id,
            'user_id' => $user->id,
            'permission' => $permission,
        ]);
    }

    /** @return array<int, string> the names a section lists for this person */
    private function names(string $section, ?User $as = null): array
    {
        $url = $section === 'home' ? '/wiki' : "/wiki/{$section}";

        return collect($this->actingAs($as ?? $this->owner)->get($url)->assertOk()->viewData('bootstrap')['collections'])
            ->pluck('name')
            ->all();
    }

    // ---- the screens exist ---------------------------------------------------------------

    public function test_all_three_sections_open_and_name_themselves(): void
    {
        $this->setUpWiki();

        foreach (['shared' => 'Shared', 'private' => 'Private', 'archived' => 'Archived'] as $key => $label) {
            $this->actingAs($this->owner)->get("/wiki/{$key}")->assertOk()->assertSee($label);
        }
    }

    public function test_a_section_that_is_not_one_of_the_three_is_not_a_screen(): void
    {
        $this->setUpWiki();

        // Enumerated in the route, so this is a 404 rather than an empty list rendered under a
        // heading nobody wrote.
        $this->actingAs($this->owner)->get('/wiki/nonsense')->assertNotFound();
    }

    public function test_the_sections_are_unreachable_when_the_workspace_has_wiki_switched_off(): void
    {
        $this->setUpWiki();
        app(WorkspaceApps::class)->sync($this->workspace, []);

        $this->actingAs($this->owner)->get('/wiki/private')->assertNotFound();
        $this->actingAs($this->owner)->get('/wiki/shared')->assertNotFound();
        $this->actingAs($this->owner)->get('/wiki/archived')->assertNotFound();
    }

    // ---- Private -------------------------------------------------------------------------

    public function test_private_lists_the_private_collections_you_made(): void
    {
        $this->setUpWiki();
        $this->collection(['name' => 'Runbooks']);
        $this->collection(['name' => 'Handbook', 'visibility' => 'public']);

        $this->assertSame(['Runbooks'], $this->names('private'));
    }

    public function test_private_is_a_lens_and_not_a_move(): void
    {
        $this->setUpWiki();
        $this->collection(['name' => 'Runbooks']);

        // The rule most likely to regress: Collections stays the full list of what you can open.
        $this->assertContains('Runbooks', $this->names('home'));
        $this->assertContains('Runbooks', $this->names('private'));
    }

    public function test_private_omits_somebody_elses_collection_you_were_invited_to(): void
    {
        $this->setUpWiki();
        $sarah = $this->mate();
        $theirs = $this->collection(['name' => 'Their Runbooks', 'created_by' => $sarah->id]);
        $this->invite($theirs, $this->owner);

        // That is Shared's job.
        $this->assertSame([], $this->names('private'));
    }

    public function test_private_omits_your_own_archived_collections(): void
    {
        $this->setUpWiki();
        $this->collection(['name' => 'Runbooks', 'archived_at' => now()]);

        $this->assertSame([], $this->names('private'));
    }

    // ---- Shared --------------------------------------------------------------------------

    public function test_shared_lists_private_collections_somebody_else_put_you_on(): void
    {
        $this->setUpWiki();
        $sarah = $this->mate();
        $theirs = $this->collection(['name' => 'Their Runbooks', 'created_by' => $sarah->id]);
        $this->invite($theirs, $this->owner);

        $this->assertSame(['Their Runbooks'], $this->names('shared'));
    }

    public function test_a_read_only_invitation_is_still_an_invitation(): void
    {
        $this->setUpWiki();
        $sarah = $this->mate();
        $theirs = $this->collection(['name' => 'Their Runbooks', 'created_by' => $sarah->id]);
        $this->invite($theirs, $this->owner, 'read');

        $this->assertSame(['Their Runbooks'], $this->names('shared'));
    }

    public function test_shared_omits_your_own_collections_however_many_people_are_on_them(): void
    {
        $this->setUpWiki();
        $sarah = $this->mate();
        $mine = $this->collection(['name' => 'Runbooks']);
        $this->invite($mine, $sarah, 'edit');

        $this->assertSame([], $this->names('shared'));
    }

    public function test_shared_omits_public_collections(): void
    {
        $this->setUpWiki();
        $sarah = $this->mate();
        $this->collection(['name' => 'Handbook', 'visibility' => 'public', 'created_by' => $sarah->id]);

        // Visible to everyone is not shared with you — nobody made a decision about you.
        $this->assertSame([], $this->names('shared'));
    }

    public function test_shared_keeps_a_collection_whose_creator_no_longer_has_an_account(): void
    {
        $this->setUpWiki();
        $orphan = $this->collection(['name' => 'Their Runbooks', 'created_by' => null]);
        $this->invite($orphan, $this->owner);

        // SQL drops NULL rows from `created_by != id`, so without the explicit null branch this
        // collection disappears from the one list that should still carry it.
        $this->assertSame(['Their Runbooks'], $this->names('shared'));
    }

    // ---- Archived ------------------------------------------------------------------------

    public function test_archived_lists_retired_collections_and_omits_live_ones(): void
    {
        $this->setUpWiki();
        $this->collection(['name' => 'Runbooks', 'archived_at' => now()]);
        $this->collection(['name' => 'Handbook']);

        $this->assertSame(['Runbooks'], $this->names('archived'));
        $this->assertSame(['Handbook'], $this->names('home'));
    }

    public function test_archived_reads_most_recently_retired_first(): void
    {
        $this->setUpWiki();
        $this->collection(['name' => 'Older', 'archived_at' => now()->subWeek(), 'position' => 1]);
        $this->collection(['name' => 'Newer', 'archived_at' => now(), 'position' => 2]);

        // An archive is a history, not an arrangement — `position` orders the things you
        // navigate, and these have left navigation.
        $this->assertSame(['Newer', 'Older'], $this->names('archived'));
    }

    public function test_archiving_takes_a_collection_out_of_its_members_archive_too(): void
    {
        $this->setUpWiki();
        $sarah = $this->mate();
        $mine = $this->collection(['name' => 'Runbooks']);
        $this->invite($mine, $sarah, 'edit');

        $mine->forceFill(['archived_at' => now()])->save();

        // Archiving revokes member access, so it is not "theirs, archived" — it is gone from
        // every list they have.
        $this->assertSame([], $this->names('archived', $sarah));
        $this->assertSame([], $this->names('home', $sarah));
        $this->assertSame(['Runbooks'], $this->names('archived'));
    }

    // ---- the filter itself -----------------------------------------------------------------

    public function test_a_private_collection_you_cannot_open_is_never_named_to_you(): void
    {
        $this->setUpWiki();
        $sarah = $this->mate();
        $this->collection(['name' => 'Board Papers']);

        // The leak this scope exists to close: naming it in the sidebar and then answering 403
        // has already told them it exists, which is what "private" was there to prevent.
        $this->assertSame([], $this->names('home', $sarah));
        $this->actingAs($sarah)->get('/wiki')->assertOk()->assertDontSee('Board Papers');
    }

    public function test_a_workspace_admin_does_not_see_private_collections_they_were_never_invited_to(): void
    {
        $this->setUpWiki();
        $admin = $this->mate('admin@example.com', 'admin');
        $this->collection(['name' => 'Board Papers']);

        // Deliberate boundary: running the workspace is not an invitation, and the visibility
        // dialog's own copy promises as much.
        $this->assertSame([], $this->names('home', $admin));
    }

    public function test_a_workspace_admin_keeps_archived_collections_they_could_already_open(): void
    {
        $this->setUpWiki();
        $admin = $this->mate('admin@example.com', 'admin');
        $this->collection(['name' => 'Handbook', 'visibility' => 'public', 'archived_at' => now()]);

        $this->assertSame(['Handbook'], $this->names('archived', $admin));
    }

    public function test_the_lenses_never_cross_workspaces(): void
    {
        $this->setUpWiki();
        $this->collection(['name' => 'Runbooks']);

        $stranger = User::factory()->create(['email' => 'stranger@example.com']);
        $other = app(WorkspaceCreator::class)->create($stranger, [
            'name' => 'Other Ltd', 'slug' => 'other-ltd', 'company_size' => '2-10',
            'apps' => ['projects', 'wiki'],
        ]);

        tenancy()->initialize($other);

        $this->assertSame([], $this->names('home', $stranger->fresh()));
        $this->assertSame([], $this->names('private', $stranger->fresh()));
    }
}
