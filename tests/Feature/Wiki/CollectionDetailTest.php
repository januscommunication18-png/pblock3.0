<?php

namespace Tests\Feature\Wiki;

use App\Models\User;
use App\Models\WikiCollection;
use App\Models\WikiCollectionMember;
use App\Models\WikiCover;
use App\Models\WikiPage;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\WorkspaceCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A collection's detail screen and its access list (docs/features/wiki.md).
 *
 * "Access follows the collection so you don't have to configure every page individually" — so
 * these rules are about the collection, and every page inside it will inherit them.
 */
class CollectionDetailTest extends TestCase
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

    private function mate(string $email = 'sarah@example.com'): User
    {
        $user = User::factory()->create(['full_name' => 'Sarah Johnson', 'email' => $email]);

        WorkspaceMembership::create([
            'workspace_id' => $this->workspace->id, 'user_id' => $user->id,
            'role' => 'member', 'status' => 'active', 'joined_at' => now(),
        ]);
        $user->forceFill(['current_workspace_id' => $this->workspace->id])->save();

        return $user->fresh();
    }

    private function collection(string $visibility = 'private', ?int $owner = null): WikiCollection
    {
        return WikiCollection::create([
            'tenant_id' => $this->workspace->id,
            'name' => 'Help Desk Software',
            'description' => 'Troubleshooting guides and escalation processes.',
            'visibility' => $visibility,
            'created_by' => $owner ?? $this->owner->id,
            'position' => 1,
        ]);
    }

    // ---- the screen -------------------------------------------------------------------------

    public function test_the_detail_screen_carries_the_name_visibility_and_description(): void
    {
        $this->setUpWiki();
        $collection = $this->collection();

        $bootstrap = $this->actingAs($this->owner)
            ->get("/wiki/collections/{$collection->id}")
            ->assertOk()
            ->viewData('bootstrap');

        $this->assertSame('Help Desk Software', $bootstrap['collection']['name']);
        $this->assertSame('private', $bootstrap['collection']['visibility']);
        $this->assertStringContainsString('Troubleshooting', $bootstrap['collection']['description']);
        // The person who made it is shown beside the invited people, but is not one of them.
        $this->assertSame($this->owner->id, $bootstrap['collection']['owner']['id']);
    }

    public function test_the_access_list_names_who_can_open_a_private_collection(): void
    {
        $this->setUpWiki();
        $collection = $this->collection();
        $sarah = $this->mate();

        $this->actingAs($this->owner)
            ->postJson("/wiki/collections/{$collection->id}/members", [
                'user_id' => $sarah->id, 'permission' => 'edit',
            ])->assertOk();

        $bootstrap = $this->actingAs($this->owner)
            ->get("/wiki/collections/{$collection->id}")->assertOk()->viewData('bootstrap');

        $this->assertCount(1, $bootstrap['members']);
        $this->assertSame('Sarah Johnson', $bootstrap['members'][0]['user']['name']);
        $this->assertSame('Can edit', $bootstrap['members'][0]['permission_label']);
    }

    public function test_somebody_already_on_the_collection_is_not_offered_again(): void
    {
        $this->setUpWiki();
        $collection = $this->collection();
        $sarah = $this->mate();

        $before = $this->actingAs($this->owner)
            ->get("/wiki/collections/{$collection->id}")->viewData('bootstrap');
        $this->assertSame([$sarah->id], collect($before['candidates'])->pluck('id')->all());

        $this->actingAs($this->owner)->postJson("/wiki/collections/{$collection->id}/members", [
            'user_id' => $sarah->id, 'permission' => 'read',
        ])->assertOk();

        // Offering a name that is already on the list invites a confusing no-op.
        $after = $this->actingAs($this->owner)
            ->get("/wiki/collections/{$collection->id}")->viewData('bootstrap');
        $this->assertSame([], $after['candidates']);
    }

    // ---- access ------------------------------------------------------------------------------

    public function test_a_private_collection_is_closed_to_everyone_not_on_it(): void
    {
        $this->setUpWiki();
        $collection = $this->collection();
        $stranger = $this->mate('stranger@example.com');

        // Being unlisted is not what makes it private.
        $this->actingAs($stranger)->get("/wiki/collections/{$collection->id}")->assertForbidden();
    }

    public function test_being_invited_opens_a_private_collection(): void
    {
        $this->setUpWiki();
        $collection = $this->collection();
        $sarah = $this->mate();

        $this->actingAs($this->owner)->postJson("/wiki/collections/{$collection->id}/members", [
            'user_id' => $sarah->id, 'permission' => 'read',
        ])->assertOk();

        $this->actingAs($sarah)->get("/wiki/collections/{$collection->id}")->assertOk();
    }

    public function test_a_public_collection_is_open_to_the_whole_workspace(): void
    {
        $this->setUpWiki();
        $collection = $this->collection('public');
        $anyone = $this->mate('anyone@example.com');

        $this->actingAs($anyone)->get("/wiki/collections/{$collection->id}")->assertOk();
    }

    public function test_the_creator_never_locks_themselves_out(): void
    {
        $this->setUpWiki();
        $sarah = $this->mate();
        $collection = $this->collection('private', owner: $sarah->id);

        // Making your own collection private and then being unable to open it would be a
        // trap, not a permission.
        $this->actingAs($sarah)->get("/wiki/collections/{$collection->id}")->assertOk();
    }

    // ---- managing access ----------------------------------------------------------------------

    public function test_only_the_owner_or_an_admin_may_change_who_has_access(): void
    {
        $this->setUpWiki();
        $collection = $this->collection();
        $sarah = $this->mate();
        $other = $this->mate('other@example.com');

        $this->actingAs($this->owner)->postJson("/wiki/collections/{$collection->id}/members", [
            'user_id' => $sarah->id, 'permission' => 'read',
        ])->assertOk();

        // Being ON the collection is not the same as controlling who else is.
        $this->actingAs($sarah)->postJson("/wiki/collections/{$collection->id}/members", [
            'user_id' => $other->id, 'permission' => 'edit',
        ])->assertForbidden();
    }

    public function test_access_can_be_taken_away(): void
    {
        $this->setUpWiki();
        $collection = $this->collection();
        $sarah = $this->mate();

        $this->actingAs($this->owner)->postJson("/wiki/collections/{$collection->id}/members", [
            'user_id' => $sarah->id, 'permission' => 'read',
        ])->assertOk();

        $member = WikiCollectionMember::query()->firstOrFail();

        $this->actingAs($this->owner)
            ->deleteJson("/wiki/collections/{$collection->id}/members/{$member->id}")
            ->assertOk();

        $this->actingAs($sarah)->get("/wiki/collections/{$collection->id}")->assertForbidden();
    }

    public function test_somebody_outside_the_workspace_cannot_be_invited(): void
    {
        $this->setUpWiki();
        $collection = $this->collection();
        $outsider = User::factory()->create(['email' => 'outsider@example.com']);

        // Sharing a collection is not a side door into the workspace.
        $this->actingAs($this->owner)->postJson("/wiki/collections/{$collection->id}/members", [
            'user_id' => $outsider->id, 'permission' => 'read',
        ])->assertStatus(422);

        $this->assertSame(0, WikiCollectionMember::query()->count());
    }

    public function test_an_unknown_permission_is_refused(): void
    {
        $this->setUpWiki();
        $collection = $this->collection();
        $sarah = $this->mate();

        // "Comment" is named in the requirements as planned, not built — accepting it would
        // grant an access level nothing enforces.
        $this->actingAs($this->owner)->postJson("/wiki/collections/{$collection->id}/members", [
            'user_id' => $sarah->id, 'permission' => 'comment',
        ])->assertStatus(422)->assertJsonValidationErrors('permission');
    }

    public function test_inviting_the_same_person_twice_updates_rather_than_duplicates(): void
    {
        $this->setUpWiki();
        $collection = $this->collection();
        $sarah = $this->mate();

        foreach (['read', 'edit'] as $permission) {
            $this->actingAs($this->owner)->postJson("/wiki/collections/{$collection->id}/members", [
                'user_id' => $sarah->id, 'permission' => $permission,
            ])->assertOk();
        }

        $this->assertSame(1, WikiCollectionMember::query()->count());
        $this->assertSame('edit', WikiCollectionMember::query()->value('permission'));
    }

    // ---- editing the collection ---------------------------------------------------------------

    public function test_a_collection_can_be_renamed_redescribed_and_reclassified(): void
    {
        $this->setUpWiki();
        $collection = $this->collection('public');

        $this->actingAs($this->owner)
            ->patchJson("/wiki/collections/{$collection->id}", [
                'name' => 'Help Desk Software',
                'description' => 'Escalation processes and service standards.',
                'visibility' => 'private',
            ])->assertOk();

        $collection->refresh();

        $this->assertSame('Help Desk Software', $collection->name);
        $this->assertStringContainsString('service standards', $collection->description);
        $this->assertTrue($collection->isPrivate());
    }

    public function test_making_a_collection_private_closes_it_to_everyone_not_on_it(): void
    {
        $this->setUpWiki();
        $collection = $this->collection('public');
        $anyone = $this->mate('anyone@example.com');

        $this->actingAs($anyone)->get("/wiki/collections/{$collection->id}")->assertOk();

        $this->actingAs($this->owner)
            ->patchJson("/wiki/collections/{$collection->id}", [
                'name' => 'Help Desk Software', 'visibility' => 'private',
            ])->assertOk();

        // Which is exactly why the dialog warns before the click, not after.
        $this->actingAs($anyone)->get("/wiki/collections/{$collection->id}")->assertForbidden();
    }

    public function test_only_the_owner_or_an_admin_may_edit_the_collection(): void
    {
        $this->setUpWiki('private');
        $collection = $this->collection('private');
        $sarah = $this->mate('sarah@example.com');

        $this->actingAs($this->owner)->postJson("/wiki/collections/{$collection->id}/members", [
            'user_id' => $sarah->id, 'permission' => 'edit',
        ])->assertOk();

        // Editing PAGES is not the same as changing what the collection is or who can see it.
        $this->actingAs($sarah)
            ->patchJson("/wiki/collections/{$collection->id}", [
                'name' => 'Mine now', 'visibility' => 'public',
            ])->assertForbidden();
    }

    public function test_a_collection_still_needs_a_name_after_editing(): void
    {
        $this->setUpWiki();
        $collection = $this->collection();

        $this->actingAs($this->owner)
            ->patchJson("/wiki/collections/{$collection->id}", ['name' => '  ', 'visibility' => 'public'])
            ->assertStatus(422)->assertJsonValidationErrors('name');
    }

    // ---- the Access box (docs/features/wiki-external-guests.md) ------------------------------

    /*
     * The box itself is drawn by Vue, so its markup is the browser suite's to assert
     * (tests/Browser/WikiCollectionsTest.php). What the SERVER owes it is the payload the two
     * invitations are gated on, and that is what these check.
     */
    public function test_the_access_payload_reaches_a_public_collection_too(): void
    {
        $this->setUpWiki();
        $collection = $this->collection('public');
        $sarah = $this->mate();
        // A second workspace member, so `candidates` still has somebody left to offer once
        // Sarah is on the collection — an empty picker would prove nothing either way.
        $this->mate('tom@example.com');

        $this->actingAs($this->owner)->postJson("/wiki/collections/{$collection->id}/members", [
            'user_id' => $sarah->id, 'permission' => 'read',
        ])->assertOk();

        // The box was private-only, because visibility was the whole answer to "who can read
        // this". A collection that can carry people from outside the workspace needs the rest of
        // it, so the payload has to arrive on a public collection as well.
        $bootstrap = $this->actingAs($this->owner)
            ->get("/wiki/collections/{$collection->id}")->assertOk()->viewData('bootstrap');

        $this->assertTrue($bootstrap['canManage']);
        $this->assertCount(1, $bootstrap['members']);
        $this->assertNotEmpty($bootstrap['candidates']);
        $this->assertNotEmpty($bootstrap['permissions']);
    }

    public function test_a_member_who_cannot_manage_is_offered_neither_invitation(): void
    {
        $this->setUpWiki();
        $collection = $this->collection();
        $sarah = $this->mate();

        WikiCollectionMember::create([
            'tenant_id' => $this->workspace->id, 'wiki_collection_id' => $collection->id,
            'user_id' => $sarah->id, 'permission' => 'edit',
        ]);

        // Deciding who may read is not an editorial act.
        $bootstrap = $this->actingAs($sarah)
            ->get("/wiki/collections/{$collection->id}")->assertOk()->viewData('bootstrap');

        $this->assertFalse($bootstrap['canManage']);
        $this->assertTrue($bootstrap['canEdit']);
    }

    // ---- archive, and the way back ----------------------------------------------------------

    private function page(WikiCollection $collection, string $title = 'Escalation process'): WikiPage
    {
        return WikiPage::create([
            'tenant_id' => $this->workspace->id,
            'wiki_collection_id' => $collection->id,
            'title' => $title,
            'created_by' => $this->owner->id, 'updated_by' => $this->owner->id, 'position' => 1,
        ]);
    }

    public function test_archiving_takes_a_collection_out_of_the_lists_and_keeps_everything(): void
    {
        $this->setUpWiki();
        $collection = $this->collection();
        $page = $this->page($collection);

        $this->actingAs($this->owner)
            ->patchJson("/wiki/collections/{$collection->id}/archive", ['archived' => true])
            ->assertOk()
            ->assertJsonPath('collection.archived', true);

        $this->assertNotNull($collection->fresh()->archived_at);
        // §"Archive instead of delete" — the pages are untouched.
        $this->assertDatabaseHas('wiki_pages', ['id' => $page->id]);
        $this->assertSame(0, WikiCollection::query()->active()->count());
    }

    public function test_archiving_is_reversible_from_the_same_place(): void
    {
        $this->setUpWiki();
        $collection = $this->collection();

        $this->actingAs($this->owner)
            ->patchJson("/wiki/collections/{$collection->id}/archive", ['archived' => true])->assertOk();

        // The Archived view is not built yet, so a one-way archive would be the last thing
        // anybody could do to a collection.
        $this->actingAs($this->owner)
            ->patchJson("/wiki/collections/{$collection->id}/archive", ['archived' => false])
            ->assertOk()
            ->assertJsonPath('collection.archived', false);

        $this->assertNull($collection->fresh()->archived_at);
    }

    public function test_an_archived_collection_stays_open_to_whoever_can_restore_it(): void
    {
        $this->setUpWiki();
        $collection = $this->collection();
        $collection->forceFill(['archived_at' => now()])->save();

        // Out of the lists is not the same as unreachable — it is how somebody gets back to
        // restore it.
        $this->actingAs($this->owner)
            ->get("/wiki/collections/{$collection->id}")->assertOk();
    }

    public function test_archiving_closes_a_collection_to_everybody_it_was_shared_with(): void
    {
        $this->setUpWiki();
        $collection = $this->collection();

        $reader = $this->mate();
        $writer = $this->mate('writer@example.com');

        foreach ([[$reader, 'read'], [$writer, 'edit']] as [$user, $permission]) {
            WikiCollectionMember::create([
                'tenant_id' => $this->workspace->id, 'wiki_collection_id' => $collection->id,
                'user_id' => $user->id, 'permission' => $permission,
            ]);
        }

        $collection->forceFill(['archived_at' => now()])->save();

        // An archive half the workspace can still walk into is a filing cabinet with no lock.
        $this->actingAs($reader)->get("/wiki/collections/{$collection->id}")->assertForbidden();
        $this->actingAs($writer)->get("/wiki/collections/{$collection->id}")->assertForbidden();
    }

    public function test_archiving_closes_a_public_collection_too(): void
    {
        $this->setUpWiki();
        $collection = $this->collection('public');
        $collection->forceFill(['archived_at' => now()])->save();

        // Visibility does not reopen it: archiving is a decision about the collection, and
        // "everyone in the workspace" was an answer to a different question.
        $this->actingAs($this->mate())->get("/wiki/collections/{$collection->id}")->assertForbidden();
    }

    public function test_archiving_grants_nobody_anything(): void
    {
        $this->setUpWiki();
        $collection = $this->collection();

        $admin = User::factory()->create(['full_name' => 'Ada', 'email' => 'admin@example.com']);
        WorkspaceMembership::create([
            'workspace_id' => $this->workspace->id, 'user_id' => $admin->id,
            'role' => 'admin', 'status' => 'active', 'joined_at' => now(),
        ]);
        $admin->forceFill(['current_workspace_id' => $this->workspace->id])->save();
        $admin = $admin->fresh();

        $this->actingAs($admin)->get("/wiki/collections/{$collection->id}")->assertForbidden();

        $collection->forceFill(['archived_at' => now()])->save();

        // The one thing the `&&` in openableBy() exists to stop: an admin who could not read a
        // private collection must not be able to archive their way into it.
        $this->actingAs($admin)->get("/wiki/collections/{$collection->id}")->assertForbidden();
    }

    public function test_restoring_hands_access_straight_back(): void
    {
        $this->setUpWiki();
        $collection = $this->collection();
        $sarah = $this->mate();

        WikiCollectionMember::create([
            'tenant_id' => $this->workspace->id, 'wiki_collection_id' => $collection->id,
            'user_id' => $sarah->id, 'permission' => 'edit',
        ]);

        $this->actingAs($this->owner)
            ->patchJson("/wiki/collections/{$collection->id}/archive", ['archived' => true])->assertOk();

        $this->actingAs($sarah)->get("/wiki/collections/{$collection->id}")->assertForbidden();

        // The membership survived, which is the whole difference between archiving and
        // deleting — restoring is not a re-invitation.
        $this->assertDatabaseHas('wiki_collection_members', [
            'wiki_collection_id' => $collection->id, 'user_id' => $sarah->id,
        ]);

        $this->actingAs($this->owner)
            ->patchJson("/wiki/collections/{$collection->id}/archive", ['archived' => false])->assertOk();

        $this->actingAs($sarah)->get("/wiki/collections/{$collection->id}")->assertOk();
    }

    public function test_the_creator_can_still_change_an_archived_collection(): void
    {
        $this->setUpWiki();
        $collection = $this->collection();
        $collection->forceFill(['archived_at' => now()])->save();

        // Archiving narrows WHO, not what they may do. The people who can bring it back can
        // still work on it.
        $this->actingAs($this->owner)
            ->postJson("/wiki/collections/{$collection->id}/pages", ['title' => 'Escalation process'])
            ->assertOk();

        $this->actingAs($this->owner)
            ->postJson("/wiki/collections/{$collection->id}/groups", ['name' => 'Getting started'])
            ->assertOk();
    }

    public function test_an_edit_member_cannot_change_an_archived_collection(): void
    {
        $this->setUpWiki();
        $collection = $this->collection();
        $sarah = $this->mate();

        WikiCollectionMember::create([
            'tenant_id' => $this->workspace->id, 'wiki_collection_id' => $collection->id,
            'user_id' => $sarah->id, 'permission' => 'edit',
        ]);

        $collection->forceFill(['archived_at' => now()])->save();

        $this->actingAs($sarah)
            ->postJson("/wiki/collections/{$collection->id}/pages", ['title' => 'Sneaking in'])
            ->assertForbidden();

        $this->actingAs($sarah)
            ->patchJson("/wiki/collections/{$collection->id}/cover", [
                'is_enabled' => false, 'title' => null, 'short_description' => null,
                'global_search_enabled' => true, 'previous_next_enabled' => true,
                'on_this_page_enabled' => true, 'content_alignment' => 'center', 'card_layout' => 'auto',
            ])
            ->assertForbidden();
    }

    public function test_editing_pages_does_not_carry_the_right_to_archive(): void
    {
        $this->setUpWiki();
        $collection = $this->collection();
        $sarah = $this->mate();

        WikiCollectionMember::create([
            'tenant_id' => $this->workspace->id, 'wiki_collection_id' => $collection->id,
            'user_id' => $sarah->id, 'permission' => 'edit',
        ]);

        $this->actingAs($sarah)
            ->patchJson("/wiki/collections/{$collection->id}/archive", ['archived' => true])
            ->assertForbidden();
    }

    // ---- delete ------------------------------------------------------------------------------

    public function test_deleting_a_collection_takes_everything_inside_it(): void
    {
        $this->setUpWiki();
        $collection = $this->collection();
        $page = $this->page($collection);

        $sarah = $this->mate();
        $member = WikiCollectionMember::create([
            'tenant_id' => $this->workspace->id, 'wiki_collection_id' => $collection->id,
            'user_id' => $sarah->id, 'permission' => 'read',
        ]);

        $this->actingAs($this->owner)
            ->deleteJson("/wiki/collections/{$collection->id}")
            ->assertOk()
            // The screen it was deleted from describes something that no longer exists.
            ->assertJsonPath('redirect', route('wiki.home'));

        $this->assertDatabaseMissing('wiki_collections', ['id' => $collection->id]);
        $this->assertDatabaseMissing('wiki_pages', ['id' => $page->id]);
        $this->assertDatabaseMissing('wiki_collection_members', ['id' => $member->id]);
    }

    public function test_deleting_takes_the_collections_cover_with_it(): void
    {
        $this->setUpWiki();
        $collection = $this->collection();

        $cover = WikiCover::forCollection($collection);
        $cover->fill(['title' => 'Product Knowledge Base', 'is_enabled' => true])->save();

        $this->actingAs($this->owner)->deleteJson("/wiki/collections/{$collection->id}")->assertOk();

        $this->assertDatabaseMissing('wiki_covers', ['id' => $cover->id]);
    }

    public function test_editing_pages_does_not_carry_the_right_to_delete(): void
    {
        $this->setUpWiki();
        $collection = $this->collection();
        $sarah = $this->mate();

        WikiCollectionMember::create([
            'tenant_id' => $this->workspace->id, 'wiki_collection_id' => $collection->id,
            'user_id' => $sarah->id, 'permission' => 'edit',
        ]);

        $this->actingAs($sarah)
            ->deleteJson("/wiki/collections/{$collection->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('wiki_collections', ['id' => $collection->id]);
    }

    public function test_somebody_outside_a_private_collection_cannot_delete_it(): void
    {
        $this->setUpWiki();
        $collection = $this->collection();

        $this->actingAs($this->mate())
            ->deleteJson("/wiki/collections/{$collection->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('wiki_collections', ['id' => $collection->id]);
    }
}
