<?php

namespace Tests\Feature\Wiki;

use App\Models\User;
use App\Models\WikiCollection;
use App\Models\WikiCollectionGroup;
use App\Models\WikiPage;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\WorkspaceCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Publishing a collection to a public URL (docs/features/wiki.md).
 *
 * The address is the thing people bookmark and send to customers, so most of what matters here
 * is about it NOT changing, and about what an unpublished address tells a stranger.
 */
class PublishingTest extends TestCase
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

    private function collection(string $visibility = 'public'): WikiCollection
    {
        return WikiCollection::create([
            'tenant_id' => $this->workspace->id,
            'name' => 'Help Desk Software',
            'description' => 'Escalation processes.',
            'visibility' => $visibility,
            'created_by' => $this->owner->id,
            'position' => 1,
        ]);
    }

    private function publish(WikiCollection $c): WikiCollection
    {
        $this->actingAs($this->owner)->postJson("/wiki/collections/{$c->id}/public-url")->assertOk();
        $this->actingAs($this->owner)
            ->patchJson("/wiki/collections/{$c->id}/status", ['status' => 'published'])->assertOk();

        return $c->fresh();
    }

    // ---- status ------------------------------------------------------------------------------

    public function test_a_collection_starts_as_a_draft(): void
    {
        $this->setUpWiki();

        // A collection is a working document until somebody decides otherwise.
        $this->assertSame('draft', $this->collection()->status);
    }

    public function test_a_collection_moves_through_draft_published_and_unpublished(): void
    {
        $this->setUpWiki();
        $c = $this->publish($this->collection());

        $this->assertTrue($c->isPublished());
        $this->assertNotNull($c->published_at);

        $this->actingAs($this->owner)
            ->patchJson("/wiki/collections/{$c->id}/status", ['status' => 'unpublished'])->assertOk();

        $this->assertFalse($c->refresh()->isPublished());
    }

    public function test_an_unknown_status_is_refused(): void
    {
        $this->setUpWiki();
        $c = $this->collection();

        $this->actingAs($this->owner)
            ->patchJson("/wiki/collections/{$c->id}/status", ['status' => 'live'])
            ->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_a_private_collection_cannot_be_published(): void
    {
        $this->setUpWiki();
        $c = $this->collection('private');

        $this->actingAs($this->owner)->postJson("/wiki/collections/{$c->id}/public-url")->assertOk();

        // Publishing puts it on the open internet; "private" is a statement about who may read
        // it. Both at once is not possible, so the contradiction is refused.
        $this->actingAs($this->owner)
            ->patchJson("/wiki/collections/{$c->id}/status", ['status' => 'published'])
            ->assertStatus(422);
    }

    public function test_publishing_needs_an_address_first(): void
    {
        $this->setUpWiki();
        $c = $this->collection();

        $this->actingAs($this->owner)
            ->patchJson("/wiki/collections/{$c->id}/status", ['status' => 'published'])
            ->assertStatus(422);
    }

    // ---- the address -------------------------------------------------------------------------

    public function test_the_public_url_is_generated_from_the_name(): void
    {
        $this->setUpWiki();
        $c = $this->collection();

        $this->actingAs($this->owner)->postJson("/wiki/collections/{$c->id}/public-url")->assertOk();

        // Readable, because a readable address is the one people trust enough to click.
        $this->assertSame('help-desk-software', $c->refresh()->public_slug);
    }

    public function test_the_public_url_cannot_be_regenerated(): void
    {
        $this->setUpWiki();
        $c = $this->collection();

        $this->actingAs($this->owner)->postJson("/wiki/collections/{$c->id}/public-url")->assertOk();
        $first = $c->refresh()->public_slug;

        // People bookmark it, paste it into documents and send it to customers. A second call
        // is refused rather than quietly breaking every one of those.
        $this->actingAs($this->owner)->postJson("/wiki/collections/{$c->id}/public-url")
            ->assertStatus(422);

        $this->assertSame($first, $c->refresh()->public_slug);
    }

    public function test_two_collections_with_the_same_name_get_different_addresses(): void
    {
        $this->setUpWiki();
        $a = $this->collection();
        $b = $this->collection();

        $this->actingAs($this->owner)->postJson("/wiki/collections/{$a->id}/public-url")->assertOk();
        $this->actingAs($this->owner)->postJson("/wiki/collections/{$b->id}/public-url")->assertOk();

        $this->assertSame('help-desk-software', $a->refresh()->public_slug);
        $this->assertSame('help-desk-software-2', $b->refresh()->public_slug);
    }

    public function test_only_the_owner_or_an_admin_may_publish(): void
    {
        $this->setUpWiki();
        $c = $this->collection();

        $member = User::factory()->create(['email' => 'member@example.com']);
        WorkspaceMembership::create([
            'workspace_id' => $this->workspace->id, 'user_id' => $member->id,
            'role' => 'member', 'status' => 'active', 'joined_at' => now(),
        ]);
        $member->forceFill(['current_workspace_id' => $this->workspace->id])->save();

        $this->actingAs($member->fresh())
            ->postJson("/wiki/collections/{$c->id}/public-url")->assertForbidden();
    }

    // ---- the public page ---------------------------------------------------------------------

    public function test_a_published_collection_is_readable_by_a_stranger(): void
    {
        $this->setUpWiki();
        $c = $this->publish($this->collection());

        WikiPage::create([
            'tenant_id' => $this->workspace->id, 'wiki_collection_id' => $c->id,
            'title' => 'Escalation process', 'content' => '<p>Ring the on-call engineer.</p>',
            'created_by' => $this->owner->id, 'updated_by' => $this->owner->id, 'position' => 1,
        ]);

        // Signed out entirely — that is the point of a public URL.
        $this->get("/acme-inc/{$c->public_slug}")
            ->assertOk()
            ->assertSee('Help Desk Software')
            ->assertSee('Escalation process')
            ->assertSee('Ring the on-call engineer', false);
    }

    public function test_a_draft_collection_is_not_found_rather_than_forbidden(): void
    {
        $this->setUpWiki();
        $c = $this->collection();
        $this->actingAs($this->owner)->postJson("/wiki/collections/{$c->id}/public-url")->assertOk();

        // A 403 confirms the address belongs to something real, which is exactly what an
        // unpublished page must not tell the internet.
        $this->get("/acme-inc/{$c->refresh()->public_slug}")->assertNotFound();
    }

    public function test_unpublishing_takes_it_down_but_keeps_the_address(): void
    {
        $this->setUpWiki();
        $c = $this->publish($this->collection());

        $this->get("/acme-inc/{$c->public_slug}")->assertOk();

        $this->actingAs($this->owner)
            ->patchJson("/wiki/collections/{$c->id}/status", ['status' => 'unpublished'])->assertOk();

        $this->get("/acme-inc/{$c->public_slug}")->assertNotFound();
        // Kept, so republishing restores the same address rather than issuing a new one.
        $this->assertNotNull($c->refresh()->public_slug);
    }

    public function test_making_a_published_collection_private_takes_it_off_the_internet(): void
    {
        $this->setUpWiki();
        $c = $this->publish($this->collection());

        $this->actingAs($this->owner)->patchJson("/wiki/collections/{$c->id}", [
            'name' => 'Help Desk Software', 'visibility' => 'private',
        ])->assertOk();

        // The status column still says published; the collection is private, and private wins.
        $this->get("/acme-inc/{$c->public_slug}")->assertNotFound();
    }

    public function test_an_unknown_workspace_or_slug_is_not_found(): void
    {
        $this->setUpWiki();
        $c = $this->publish($this->collection());

        $this->get("/no-such-workspace/{$c->public_slug}")->assertNotFound();
        $this->get('/acme-inc/no-such-collection')->assertNotFound();
    }

    public function test_the_public_route_never_shadows_a_real_one(): void
    {
        $this->setUpWiki();

        // Registered last, and `reserved_slugs` keeps a workspace from being named after an
        // app path — so /wiki and /settings still belong to the application.
        $this->actingAs($this->owner)->get('/wiki')->assertOk();
        $this->actingAs($this->owner)->get('/settings/general')->assertOk();
    }

    // ---- preview ------------------------------------------------------------------------------

    public function test_preview_shows_the_collection_as_a_reader_would_see_it(): void
    {
        $this->setUpWiki();
        $c = $this->collection();

        $group = WikiCollectionGroup::create([
            'tenant_id' => $this->workspace->id, 'wiki_collection_id' => $c->id,
            'name' => 'Getting started', 'created_by' => $this->owner->id, 'position' => 1,
        ]);
        WikiPage::create([
            'tenant_id' => $this->workspace->id, 'wiki_collection_id' => $c->id,
            'title' => 'Escalation process', 'content' => '<p>Ring the on-call engineer.</p>',
            'wiki_group_id' => $group->id, 'created_by' => $this->owner->id,
            'updated_by' => $this->owner->id, 'position' => 1,
        ]);

        $this->actingAs($this->owner)->get("/wiki/collections/{$c->id}/preview")
            ->assertOk()
            ->assertSee('Getting started')
            ->assertSee('Escalation process')
            ->assertSee('Ring the on-call engineer', false)
            // Said plainly, because a preview of an unpublished collection looks exactly like
            // the published one otherwise.
            ->assertSee('It is not published');
    }

    public function test_preview_works_before_anything_is_published(): void
    {
        $this->setUpWiki();
        $c = $this->collection();

        // The point of a preview is deciding whether to publish.
        $this->assertSame('draft', $c->status);
        $this->actingAs($this->owner)->get("/wiki/collections/{$c->id}/preview")->assertOk();
    }

    public function test_preview_is_closed_to_anyone_who_cannot_open_the_collection(): void
    {
        $this->setUpWiki();
        $c = $this->collection('private');

        $stranger = User::factory()->create(['email' => 'stranger@example.com']);
        WorkspaceMembership::create([
            'workspace_id' => $this->workspace->id, 'user_id' => $stranger->id,
            'role' => 'member', 'status' => 'active', 'joined_at' => now(),
        ]);
        $stranger->forceFill(['current_workspace_id' => $this->workspace->id])->save();

        $this->actingAs($stranger->fresh())->get("/wiki/collections/{$c->id}/preview")->assertForbidden();
    }

    public function test_the_published_page_and_the_preview_render_the_same_template(): void
    {
        $this->setUpWiki();
        $c = $this->publish($this->collection());

        WikiPage::create([
            'tenant_id' => $this->workspace->id, 'wiki_collection_id' => $c->id,
            'title' => 'Escalation process', 'created_by' => $this->owner->id,
            'updated_by' => $this->owner->id, 'position' => 1,
        ]);

        // What was previewed is what is published — one template, so they cannot drift.
        $this->actingAs($this->owner)->get("/wiki/collections/{$c->id}/preview")
            ->assertOk()->assertViewIs('wiki.reader');

        $this->get("/acme-inc/{$c->public_slug}")->assertOk()->assertViewIs('wiki.reader');
    }

    public function test_a_reader_can_open_a_particular_page(): void
    {
        $this->setUpWiki();
        $c = $this->publish($this->collection());

        $first = WikiPage::create([
            'tenant_id' => $this->workspace->id, 'wiki_collection_id' => $c->id,
            'title' => 'First', 'content' => '<p>One.</p>', 'created_by' => $this->owner->id,
            'updated_by' => $this->owner->id, 'position' => 1,
        ]);
        $second = WikiPage::create([
            'tenant_id' => $this->workspace->id, 'wiki_collection_id' => $c->id,
            'title' => 'Second', 'content' => '<p>Two.</p>', 'created_by' => $this->owner->id,
            'updated_by' => $this->owner->id, 'position' => 2,
        ]);

        // Defaults to the first page — a documentation site that opens on nothing is a
        // documentation site nobody reads.
        $this->get("/acme-inc/{$c->public_slug}")->assertOk()->assertSee('One.', false);
        $this->get("/acme-inc/{$c->public_slug}?page={$second->id}")->assertOk()->assertSee('Two.', false);

        $this->assertNotNull($first);
    }
}
