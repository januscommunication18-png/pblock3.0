<?php

namespace Tests\Feature\Wiki;

use App\Mail\WikiGuestInvitationMail;
use App\Models\User;
use App\Models\WikiCollection;
use App\Models\WikiCollectionGuest;
use App\Models\WikiCollectionMember;
use App\Models\WikiPage;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\WorkspaceApps;
use App\Services\WorkspaceCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * External members of a collection (docs/features/wiki-external-guests.md).
 *
 * A guest is an address with a token against ONE collection. They have no account, reach nothing
 * else, and removing the row is the whole of revocation.
 */
class GuestsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    private WikiCollection $collection;

    private function setUpWiki(string $visibility = 'private'): void
    {
        Mail::fake();

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

    private function page(string $title = 'Escalation process'): WikiPage
    {
        return WikiPage::create([
            'tenant_id' => $this->workspace->id,
            'wiki_collection_id' => $this->collection->id,
            'title' => $title, 'content' => '<p>Ring the on-call.</p>',
            'created_by' => $this->owner->id, 'updated_by' => $this->owner->id, 'position' => 1,
        ]);
    }

    private function invite(array $overrides = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner)
            ->postJson("/wiki/collections/{$this->collection->id}/guests", array_merge([
                'name' => 'Sarah Lee',
                'email' => 'sarah@client.com',
                'login_method' => 'magic_link',
            ], $overrides));
    }

    /**
     * The raw token never leaves the mailable, so this is where a test has to read it.
     *
     * `assertQueued`, not `assertSent`: the mailable is ShouldQueue (CLAUDE.md §11), so sending
     * it hands it to the queue rather than to the transport.
     */
    private function sentLink(): string
    {
        $link = null;

        Mail::assertQueued(WikiGuestInvitationMail::class, function (WikiGuestInvitationMail $mail) use (&$link) {
            $link = $mail->openUrl;

            return true;
        });

        return $link;
    }

    // ---- inviting ---------------------------------------------------------------------------

    public function test_inviting_an_external_member_stores_them_and_sends_a_link(): void
    {
        $this->setUpWiki();

        $this->invite()->assertOk()->assertJsonPath('guests.0.email', 'sarah@client.com');

        $guest = WikiCollectionGuest::query()->firstOrFail();

        $this->assertSame('Sarah Lee', $guest->name);
        $this->assertSame($this->collection->id, (int) $guest->wiki_collection_id);
        $this->assertSame($this->owner->id, (int) $guest->invited_by);
        $this->assertNull($guest->last_seen_at);

        Mail::assertQueued(WikiGuestInvitationMail::class);
    }

    public function test_only_the_hash_of_the_token_is_ever_stored(): void
    {
        $this->setUpWiki();
        $this->invite()->assertOk();

        $link = $this->sentLink();
        $raw = basename(parse_url($link, PHP_URL_PATH));
        $guest = WikiCollectionGuest::query()->firstOrFail();

        // A database dump must not be a set of working links.
        $this->assertNotSame($raw, $guest->token);
        $this->assertSame(WikiCollectionGuest::hashToken($raw), $guest->token);
        $this->assertSame(64, strlen($raw));
    }

    public function test_the_address_is_normalized_and_the_name_trimmed(): void
    {
        $this->setUpWiki();

        $this->invite(['name' => '  Sarah Lee  ', 'email' => '  Sarah@Client.com '])->assertOk();

        $guest = WikiCollectionGuest::query()->firstOrFail();

        $this->assertSame('sarah@client.com', $guest->email);
        $this->assertSame('Sarah Lee', $guest->name);
    }

    public function test_re_inviting_the_same_address_resends_rather_than_duplicating(): void
    {
        $this->setUpWiki();

        $this->invite()->assertOk();
        $first = WikiCollectionGuest::query()->firstOrFail()->token;

        $this->invite(['name' => 'Sarah Lee-Smith'])->assertOk();

        $this->assertDatabaseCount('wiki_collection_guests', 1);

        $guest = WikiCollectionGuest::query()->firstOrFail();

        $this->assertSame('Sarah Lee-Smith', $guest->name);
        // A fresh token, so the previous email stops working — which is how a forwarded link is
        // cut off without removing the person.
        $this->assertNotSame($first, $guest->token);
    }

    public function test_a_login_method_the_application_cannot_perform_is_refused(): void
    {
        $this->setUpWiki();

        $this->invite(['login_method' => 'password'])
            ->assertStatus(422)->assertJsonValidationErrors('login_method');

        $this->assertDatabaseCount('wiki_collection_guests', 0);
    }

    public function test_a_name_and_a_real_address_are_both_required(): void
    {
        $this->setUpWiki();

        $this->invite(['name' => '  '])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->invite(['email' => 'not-an-address'])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    // ---- who may invite ---------------------------------------------------------------------

    public function test_editing_pages_does_not_carry_the_right_to_invite_outsiders(): void
    {
        $this->setUpWiki();
        $sarah = $this->mate();

        WikiCollectionMember::create([
            'tenant_id' => $this->workspace->id, 'wiki_collection_id' => $this->collection->id,
            'user_id' => $sarah->id, 'permission' => 'edit',
        ]);

        // Deciding who may read is not an editorial act.
        $this->invite(as: $sarah)->assertForbidden();
        $this->assertDatabaseCount('wiki_collection_guests', 0);
    }

    public function test_the_endpoints_are_unreachable_with_wiki_switched_off(): void
    {
        $this->setUpWiki();
        app(WorkspaceApps::class)->sync($this->workspace, []);

        $this->invite()->assertNotFound();
    }

    // ---- the guest's door -------------------------------------------------------------------

    public function test_the_link_opens_the_collection_and_records_the_visit(): void
    {
        $this->setUpWiki();
        $this->page();
        $this->invite()->assertOk();

        // Signed out — a guest has no account at all.
        $this->get($this->sentLink())
            ->assertOk()
            ->assertSee('Ring the on-call.', false)
            ->assertSee('Shared with you by');

        $this->assertNotNull(WikiCollectionGuest::query()->firstOrFail()->last_seen_at);
    }

    public function test_the_link_works_on_a_draft_collection(): void
    {
        $this->setUpWiki();
        $this->page();
        $this->invite()->assertOk();

        // The invitation is the authorization — publication is not consulted. This is the whole
        // point: a draft can be shared with one client without going on the internet first.
        $this->assertSame('draft', $this->collection->fresh()->status);
        $this->get($this->sentLink())->assertOk();
    }

    public function test_the_guest_page_is_kept_out_of_search_indexes(): void
    {
        $this->setUpWiki();
        $this->page();
        $this->invite()->assertOk();

        $this->get($this->sentLink())->assertOk()->assertSee('noindex, nofollow', false);
    }

    public function test_removing_a_guest_kills_the_link_immediately(): void
    {
        $this->setUpWiki();
        $this->page();
        $this->invite()->assertOk();

        $link = $this->sentLink();
        $this->get($link)->assertOk();

        $guest = WikiCollectionGuest::query()->firstOrFail();

        $this->actingAs($this->owner)
            ->deleteJson("/wiki/collections/{$this->collection->id}/guests/{$guest->id}")
            ->assertOk();

        // The row IS the access, and it is re-read on every request.
        $this->get($link)->assertNotFound();
    }

    public function test_resending_replaces_the_link(): void
    {
        $this->setUpWiki();
        $this->page();
        $this->invite()->assertOk();

        $old = $this->sentLink();
        $guest = WikiCollectionGuest::query()->firstOrFail();

        $this->actingAs($this->owner)
            ->postJson("/wiki/collections/{$this->collection->id}/guests/{$guest->id}/resend")
            ->assertOk();

        $this->get($old)->assertNotFound();
    }

    public function test_an_archived_collection_is_closed_to_its_guests(): void
    {
        $this->setUpWiki();
        $this->page();
        $this->invite()->assertOk();

        $link = $this->sentLink();
        $this->collection->forceFill(['archived_at' => now()])->save();

        // Retired cannot mean "retired, except for the client" (WIKI-D5).
        $this->get($link)->assertNotFound();
    }

    public function test_a_token_that_belongs_to_nothing_is_a_404(): void
    {
        $this->setUpWiki();

        // Never a 403 — that would confirm the address belongs to something real, which is
        // exactly what a withdrawn link must not tell whoever is holding it.
        $this->get('/wiki/guest/'.str_repeat('a', 64))->assertNotFound();
    }

    public function test_a_guest_reaches_no_other_collection(): void
    {
        $this->setUpWiki();
        $this->page();
        $this->invite()->assertOk();
        $link = $this->sentLink();

        $other = WikiCollection::create([
            'tenant_id' => $this->workspace->id, 'name' => 'Board Papers',
            'visibility' => 'private', 'created_by' => $this->owner->id, 'position' => 2,
        ]);

        // One token, one collection — enforced by the data rather than by a filter somebody
        // could forget.
        $this->get($link)->assertOk()->assertDontSee('Board Papers');

        // Explicitly signed out: inviting used actingAs, and leaving that in place would have
        // this asserting the OWNER's access rather than the guest's.
        auth()->logout();

        // Opening the link grants NO session on a user — which is the whole claim. Everything
        // behind `auth` is therefore closed to them without this test having to enumerate it.
        $this->get($link)->assertOk();
        $this->assertGuest();

        // And the other collection is not even named on the page they can see.
        $this->get($link)->assertOk()->assertDontSee('Board Papers');
    }

    public function test_the_token_never_reaches_the_collection_screen(): void
    {
        $this->setUpWiki();
        $this->invite()->assertOk();

        $raw = basename(parse_url($this->sentLink(), PHP_URL_PATH));
        $guest = WikiCollectionGuest::query()->firstOrFail();

        $response = $this->actingAs($this->owner)->get("/wiki/collections/{$this->collection->id}");

        // A credential belongs in one email and nowhere else — not in a payload the browser can
        // read, and not on any screen.
        $response->assertOk()->assertDontSee($raw, false)->assertDontSee($guest->token, false);
        $response->assertSee('sarah@client.com', false);
    }
}
