<?php

namespace Tests\Feature\Wiki;

use App\Models\User;
use App\Models\WikiCollection;
use App\Models\WikiCollectionGroup;
use App\Models\WikiCollectionMember;
use App\Models\WikiCover;
use App\Models\WikiPage;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\WikiReader;
use App\Services\WorkspaceApps;
use App\Services\WorkspaceCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A collection's Cover Page settings (docs/features/wiki-cover-page.md).
 *
 * The cover belongs to a COLLECTION, is configured from the card pinned above the Group view's
 * sections, and decides nothing about who may read anything.
 */
class CoverTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    private WikiCollection $collection;

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
            'tenant_id' => $this->workspace->id, 'name' => 'Help Desk Software',
            'visibility' => 'public', 'created_by' => $this->owner->id, 'position' => 1,
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

    /** @return array<string, mixed> */
    private function settings(array $overrides = []): array
    {
        return array_merge([
            'is_enabled' => true,
            'title' => 'Product Knowledge Base',
            'short_description' => 'Everything the team needs.',
            'global_search_enabled' => true,
            'previous_next_enabled' => true,
            'on_this_page_enabled' => true,
            'content_alignment' => 'center',
            'card_layout' => 'auto',
        ], $overrides);
    }

    private function save(array $overrides = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner)
            ->patchJson("/wiki/collections/{$this->collection->id}/cover", $this->settings($overrides));
    }

    // ---- the card's starting state ----------------------------------------------------------

    public function test_a_collection_starts_with_a_cover_that_is_off_but_fully_described(): void
    {
        $this->setUpWiki();

        // No row has been written — opening a collection is not a decision to give it a cover.
        $this->assertDatabaseCount('wiki_covers', 0);

        $cover = WikiCover::forCollection($this->collection)->toCard();

        $this->assertFalse($cover['is_enabled']);
        $this->assertNull($cover['title']);
        $this->assertTrue($cover['global_search_enabled']);
        $this->assertTrue($cover['previous_next_enabled']);
        $this->assertTrue($cover['on_this_page_enabled']);
        $this->assertSame('center', $cover['content_alignment']);
        $this->assertSame('auto', $cover['card_layout']);
    }

    public function test_the_collection_screen_carries_the_cover_and_its_layout_choices(): void
    {
        $this->setUpWiki();

        $response = $this->actingAs($this->owner)->get("/wiki/collections/{$this->collection->id}");

        $response->assertOk();
        $response->assertSee('"cover"', false);
        $response->assertSee('Left align', false);
        $response->assertSee('3 columns', false);
        $response->assertSee('/cover', false);
    }

    // ---- saving -----------------------------------------------------------------------------

    public function test_the_first_save_writes_the_row(): void
    {
        $this->setUpWiki();

        $this->save()->assertOk()->assertJsonPath('cover.title', 'Product Knowledge Base');

        $cover = WikiCover::query()->firstOrFail();

        $this->assertTrue($cover->is_enabled);
        $this->assertSame($this->workspace->id, $cover->tenant_id);
        $this->assertSame($this->collection->id, (int) $cover->wiki_collection_id);
        $this->assertSame($this->owner->id, (int) $cover->created_by);
        $this->assertSame($this->owner->id, (int) $cover->updated_by);
    }

    public function test_saving_twice_updates_the_one_row(): void
    {
        $this->setUpWiki();

        $this->save()->assertOk();
        $this->save(['title' => 'Engineering Handbook'])->assertOk();

        $this->assertDatabaseCount('wiki_covers', 1);
        $this->assertSame('Engineering Handbook', WikiCover::query()->firstOrFail()->title);
    }

    public function test_the_title_is_trimmed_and_a_blank_description_is_stored_as_null(): void
    {
        $this->setUpWiki();

        $this->save(['title' => '  Product Knowledge Base  ', 'short_description' => '   '])->assertOk();

        $cover = WikiCover::query()->firstOrFail();

        $this->assertSame('Product Knowledge Base', $cover->title);
        $this->assertNull($cover->short_description);
    }

    // ---- WCOV-2 -----------------------------------------------------------------------------

    public function test_a_cover_cannot_be_turned_on_without_a_title(): void
    {
        $this->setUpWiki();

        $this->save(['is_enabled' => true, 'title' => null])
            ->assertStatus(422)
            ->assertJsonValidationErrors('title');

        $this->assertDatabaseCount('wiki_covers', 0);
    }

    public function test_whitespace_is_not_a_title(): void
    {
        $this->setUpWiki();

        $this->save(['is_enabled' => true, 'title' => '   '])
            ->assertStatus(422)
            ->assertJsonValidationErrors('title');
    }

    public function test_a_disabled_cover_may_have_no_title(): void
    {
        $this->setUpWiki();

        $this->save(['is_enabled' => false, 'title' => null])->assertOk();

        $this->assertFalse(WikiCover::query()->firstOrFail()->is_enabled);
    }

    // ---- WCOV-1 -----------------------------------------------------------------------------

    public function test_turning_the_cover_off_keeps_everything_it_was_configured_with(): void
    {
        $this->setUpWiki();

        $this->save([
            'short_description' => 'Everything the team needs.',
            'global_search_enabled' => false,
            'content_alignment' => 'left',
            'card_layout' => 'three',
        ])->assertOk();

        $this->save([
            'is_enabled' => false,
            'short_description' => 'Everything the team needs.',
            'global_search_enabled' => false,
            'content_alignment' => 'left',
            'card_layout' => 'three',
        ])->assertOk();

        $cover = WikiCover::query()->firstOrFail();

        $this->assertFalse($cover->is_enabled);
        $this->assertSame('Product Knowledge Base', $cover->title);
        $this->assertSame('Everything the team needs.', $cover->short_description);
        $this->assertFalse($cover->global_search_enabled);
        $this->assertSame('left', $cover->content_alignment);
        $this->assertSame('three', $cover->card_layout);
    }

    // ---- validation -------------------------------------------------------------------------

    public function test_the_title_and_description_are_bounded(): void
    {
        $this->setUpWiki();

        $this->save(['title' => str_repeat('a', 101)])
            ->assertStatus(422)->assertJsonValidationErrors('title');

        $this->save(['short_description' => str_repeat('a', 301)])
            ->assertStatus(422)->assertJsonValidationErrors('short_description');
    }

    public function test_an_alignment_or_layout_the_reader_cannot_render_is_refused(): void
    {
        $this->setUpWiki();

        $this->save(['content_alignment' => 'justified'])
            ->assertStatus(422)->assertJsonValidationErrors('content_alignment');

        $this->save(['card_layout' => '4_column'])
            ->assertStatus(422)->assertJsonValidationErrors('card_layout');
    }

    // ---- who may configure it ---------------------------------------------------------------

    public function test_a_member_who_can_edit_the_collection_can_configure_its_cover(): void
    {
        $this->setUpWiki();
        $mate = $this->mate();

        WikiCollectionMember::create([
            'tenant_id' => $this->workspace->id,
            'wiki_collection_id' => $this->collection->id,
            'user_id' => $mate->id, 'permission' => 'edit',
        ]);

        $this->save(as: $mate)->assertOk();
    }

    public function test_a_read_only_member_cannot(): void
    {
        $this->setUpWiki();
        $mate = $this->mate();

        WikiCollectionMember::create([
            'tenant_id' => $this->workspace->id,
            'wiki_collection_id' => $this->collection->id,
            'user_id' => $mate->id, 'permission' => 'read',
        ]);

        $this->save(as: $mate)->assertForbidden();
        $this->assertDatabaseCount('wiki_covers', 0);
    }

    public function test_somebody_who_cannot_open_a_private_collection_cannot_reach_its_cover(): void
    {
        $this->setUpWiki();

        $this->collection->forceFill(['visibility' => 'private'])->save();

        $this->save(as: $this->mate())->assertForbidden();
    }

    public function test_the_cover_is_unreachable_when_the_workspace_has_wiki_switched_off(): void
    {
        $this->setUpWiki();

        app(WorkspaceApps::class)->sync($this->workspace, []);

        $this->save()->assertNotFound();
    }

    // ---- WCOV-3, the landing page -----------------------------------------------------------

    private function page(string $title, string $content = '<h2>Escalating</h2><p>Ring the on-call.</p>'): WikiPage
    {
        return WikiPage::create([
            'tenant_id' => $this->workspace->id,
            'wiki_collection_id' => $this->collection->id,
            'title' => $title, 'content' => $content,
            'created_by' => $this->owner->id, 'updated_by' => $this->owner->id, 'position' => 1,
        ]);
    }

    public function test_preview_opens_on_the_cover_when_one_is_enabled(): void
    {
        $this->setUpWiki();
        $this->page('Escalation process');
        $this->save()->assertOk();

        $response = $this->actingAs($this->owner)
            ->get("/wiki/collections/{$this->collection->id}/preview");

        $response->assertOk();
        $response->assertSee('Product Knowledge Base');
        $response->assertSee('Everything the team needs.');
        $response->assertSee('Explore');
        // The landing screen is the cover, not the first page's document.
        $response->assertDontSee('wi-rich', false);
    }

    public function test_preview_opens_on_the_first_page_when_the_cover_is_off(): void
    {
        $this->setUpWiki();
        $this->page('Escalation process');
        $this->save(['is_enabled' => false])->assertOk();

        $this->actingAs($this->owner)
            ->get("/wiki/collections/{$this->collection->id}/preview")
            ->assertOk()
            ->assertSee('Ring the on-call.', false);
    }

    public function test_a_link_to_a_page_is_not_intercepted_by_the_cover(): void
    {
        $this->setUpWiki();
        $page = $this->page('Escalation process');
        $this->save()->assertOk();

        // Somebody followed a link somebody else sent. A landing screen in its place would
        // break every address already pasted into a chat.
        $this->actingAs($this->owner)
            ->get("/wiki/collections/{$this->collection->id}/preview?page={$page->id}")
            ->assertOk()
            ->assertSee('Ring the on-call.', false)
            ->assertDontSee('Start reading');
    }

    public function test_the_navigation_carries_the_way_back_to_the_cover(): void
    {
        $this->setUpWiki();
        $page = $this->page('Escalation process');
        $this->save()->assertOk();

        // Reading a page, the cover has to still be reachable — otherwise the front door is a
        // screen you can only get to by editing the address bar.
        $this->actingAs($this->owner)
            ->get("/wiki/collections/{$this->collection->id}/preview?page={$page->id}")
            ->assertOk()
            ->assertSee(route('wiki.collections.preview', $this->collection), false);
    }

    public function test_a_published_collection_lands_on_its_cover_too(): void
    {
        $this->setUpWiki();
        $this->page('Escalation process');
        $this->save()->assertOk();

        $this->collection->forceFill([
            'status' => 'published', 'public_slug' => 'help-desk', 'published_at' => now(),
        ])->save();

        // Signed out — what was previewed is what is published.
        $this->get('/acme-inc/help-desk')
            ->assertOk()
            ->assertSee('Product Knowledge Base')
            ->assertSee('Explore')
            ->assertDontSee('wi-rich', false);
    }

    // ---- the cards, derived from the collection ---------------------------------------------

    public function test_the_cover_draws_a_card_per_section(): void
    {
        $this->setUpWiki();

        $started = WikiCollectionGroup::create([
            'tenant_id' => $this->workspace->id, 'wiki_collection_id' => $this->collection->id,
            'name' => 'Getting started', 'short_description' => 'Read these first.',
            'created_by' => $this->owner->id, 'position' => 1,
        ]);

        $this->page('Escalation process')->forceFill(['wiki_group_id' => $started->id])->save();
        $this->page('Handover')->forceFill(['wiki_group_id' => $started->id])->save();

        $this->save()->assertOk();

        $this->actingAs($this->owner)
            ->get("/wiki/collections/{$this->collection->id}/preview")
            ->assertOk()
            ->assertSee('Getting started')
            ->assertSee('Read these first.')
            ->assertSee('2 pages');
    }

    public function test_a_section_with_no_pages_gets_no_card(): void
    {
        $this->setUpWiki();

        WikiCollectionGroup::create([
            'tenant_id' => $this->workspace->id, 'wiki_collection_id' => $this->collection->id,
            'name' => 'Runbooks', 'created_by' => $this->owner->id, 'position' => 1,
        ]);

        $this->page('Escalation process');

        // Asserted on the card list rather than the HTML: the navigation lists an empty section
        // too, under "No pages yet", so its name is on the page either way. What must not
        // happen is a CARD — a card leading nowhere is a dead end, and an empty section is a
        // heading somebody made in advance rather than a destination.
        $reader = app(WikiReader::class);
        $cards = $reader->coverCards($reader->sections($this->collection));

        $this->assertSame(['More'], array_column($cards, 'title'));
    }

    public function test_without_sections_each_page_becomes_its_own_card(): void
    {
        $this->setUpWiki();

        $this->page('Escalation process');
        $this->page('Handover', '<h2>Handing over</h2><p>Write it down.</p>');
        $this->save()->assertOk();

        $this->actingAs($this->owner)
            ->get("/wiki/collections/{$this->collection->id}/preview")
            ->assertOk()
            ->assertSee('Escalation process')
            ->assertSee('Handover')
            // The card's description is the opening of the page it leads to — with the words
            // kept apart, which strip_tags() on its own does not do.
            ->assertSee('Handing over Write it down.');
    }

    // ---- FR-WC-024 … FR-WC-027 --------------------------------------------------------------

    public function test_the_content_column_moves_with_the_alignment_setting(): void
    {
        $this->setUpWiki();
        $page = $this->page('Escalation process');
        $url = "/wiki/collections/{$this->collection->id}/preview?page={$page->id}";

        $this->save(['content_alignment' => 'center'])->assertOk();
        $this->actingAs($this->owner)->get($url)->assertOk()->assertSee('max-w-[760px] mx-auto', false);

        $this->save(['content_alignment' => 'right'])->assertOk();
        $this->actingAs($this->owner)->get($url)->assertOk()->assertSee('max-w-[760px] ml-auto', false);

        $this->save(['content_alignment' => 'left'])->assertOk();
        $this->actingAs($this->owner)->get($url)->assertOk()->assertSee('max-w-[760px] mr-auto', false);
    }

    public function test_a_collection_without_a_cover_reads_exactly_as_it_did_before(): void
    {
        $this->setUpWiki();
        $page = $this->page('Escalation process');

        // No cover row at all. `center` is the column default, but applying it to a collection
        // nobody configured would restyle every one written before this feature existed.
        $this->actingAs($this->owner)
            ->get("/wiki/collections/{$this->collection->id}/preview?page={$page->id}")
            ->assertOk()
            ->assertSee('max-w-[760px] mr-auto', false);
    }

    public function test_the_card_grid_follows_the_layout_setting(): void
    {
        $this->setUpWiki();
        $this->page('Escalation process');
        $url = "/wiki/collections/{$this->collection->id}/preview";

        $this->save(['card_layout' => 'two'])->assertOk();
        $this->actingAs($this->owner)->get($url)->assertOk()->assertSee('grid gap-4 mt-8 sm:grid-cols-2"', false);

        $this->save(['card_layout' => 'three'])->assertOk();
        $this->actingAs($this->owner)->get($url)->assertOk()->assertSee('sm:grid-cols-2 lg:grid-cols-3', false);

        $this->save(['card_layout' => 'auto'])->assertOk();
        $this->actingAs($this->owner)->get($url)->assertOk()->assertSee('sm:grid-cols-2 xl:grid-cols-3', false);
    }

    // ---- the reader's navigation ------------------------------------------------------------

    public function test_every_navigation_section_is_a_disclosure(): void
    {
        $this->setUpWiki();

        $group = WikiCollectionGroup::create([
            'tenant_id' => $this->workspace->id, 'wiki_collection_id' => $this->collection->id,
            'name' => 'Getting started', 'created_by' => $this->owner->id, 'position' => 1,
        ]);

        $page = $this->page('Escalation process');
        $page->forceFill(['wiki_group_id' => $group->id])->save();

        $response = $this->actingAs($this->owner)
            ->get("/wiki/collections/{$this->collection->id}/preview?page={$page->id}");

        $response->assertOk();
        $response->assertSee('data-nav-toggle', false);
        $response->assertSee('aria-controls="nav-s'.$group->id.'"', false);
        // The section holding the page being read is marked, so collapsing the navigation can
        // never fold away where the reader is standing.
        $response->assertSee('data-nav-current', false);
    }

    public function test_a_page_with_sub_pages_is_a_disclosure_of_its_own(): void
    {
        $this->setUpWiki();

        $parent = $this->page('Escalation process');
        $child = $this->page('Out of hours');
        $child->forceFill(['parent_id' => $parent->id])->save();

        $grandchild = $this->page('Weekends');
        $grandchild->forceFill(['parent_id' => $child->id])->save();

        // Reading the deepest page: every branch above it must already be open on the first
        // paint, not after a script has walked the DOM.
        $response = $this->actingAs($this->owner)
            ->get("/wiki/collections/{$this->collection->id}/preview?page={$grandchild->id}");

        $response->assertOk();
        $response->assertSee('aria-controls="nav-p'.$parent->id.'"', false);
        $response->assertSee('aria-controls="nav-p'.$child->id.'"', false);
        // The leaf has nothing to disclose and carries no control.
        $response->assertDontSee('aria-controls="nav-p'.$grandchild->id.'"', false);
        $response->assertSee('Weekends');
    }

    public function test_nesting_is_derived_for_the_navigation_and_the_flat_list_is_left_alone(): void
    {
        $this->setUpWiki();

        $parent = $this->page('Escalation process');
        $child = $this->page('Out of hours');
        $child->forceFill(['parent_id' => $parent->id])->save();

        $reader = app(WikiReader::class);
        $sections = $reader->sections($this->collection);

        // `sections()` stays FLAT: current(), the cover cards and the destination picker all
        // walk that list, and nesting it in place would have dropped every sub-page from all
        // three at once.
        $this->assertSame(
            ['Escalation process', 'Out of hours'],
            collect($sections[0]['pages'])->pluck('title')->all(),
        );

        $tree = $reader->tree($sections[0]['pages']);

        $this->assertCount(1, $tree);
        $this->assertSame('Escalation process', $tree[0]['page']->title);
        $this->assertSame('Out of hours', $tree[0]['children'][0]['page']->title);
    }

    public function test_a_sub_page_whose_parent_is_filed_elsewhere_is_still_drawn(): void
    {
        $this->setUpWiki();

        $group = WikiCollectionGroup::create([
            'tenant_id' => $this->workspace->id, 'wiki_collection_id' => $this->collection->id,
            'name' => 'Getting started', 'created_by' => $this->owner->id, 'position' => 1,
        ]);

        $parent = $this->page('Escalation process');
        $parent->forceFill(['wiki_group_id' => $group->id])->save();

        // A sub-page inherits its parent's group, so this should not happen — but losing the
        // page from the navigation entirely is far worse than showing it a level too high.
        $orphan = $this->page('Out of hours');
        $orphan->forceFill(['parent_id' => $parent->id])->save();

        $tree = app(WikiReader::class)->tree(collect([$orphan]));

        $this->assertCount(1, $tree);
        $this->assertSame('Out of hours', $tree[0]['page']->title);
    }

    public function test_a_cover_replaces_the_collection_description_in_the_navigation(): void
    {
        $this->setUpWiki();
        $this->collection->forceFill([
            'description' => 'Everything about the help desk, written down.',
        ])->save();

        $page = $this->page('Escalation process');
        $url = "/wiki/collections/{$this->collection->id}/preview?page={$page->id}";

        // Without a cover the description is the only introduction there is.
        $this->actingAs($this->owner)->get($url)
            ->assertOk()->assertSee('Everything about the help desk, written down.');

        $this->save()->assertOk();

        // With one, its title and short description introduce the collection — a second
        // description in the navigation is two summaries on one screen.
        $this->actingAs($this->owner)->get($url)
            ->assertOk()
            ->assertDontSee('Everything about the help desk, written down.')
            ->assertSee('Product Knowledge Base');
    }

    public function test_search_lives_in_the_header_and_answers_to_its_switch(): void
    {
        $this->setUpWiki();
        $page = $this->page('Escalation process');
        $url = "/wiki/collections/{$this->collection->id}/preview?page={$page->id}";

        $this->save(['global_search_enabled' => true])->assertOk();

        $response = $this->actingAs($this->owner)->get($url)->assertOk();

        $response->assertSee('id="wiki-search"', false);
        // The index it searches is the collection's own page names.
        $response->assertSee('Escalation process');
        // It replaced the box that used to sit at the top of the navigation.
        $response->assertDontSee('wiki-nav-filter', false);
        $response->assertDontSee('Filter pages', false);

        $this->save(['global_search_enabled' => false])->assertOk();

        $this->actingAs($this->owner)->get($url)->assertOk()->assertDontSee('id="wiki-search"', false);
    }

    // ---- the results page --------------------------------------------------------------

    public function test_searching_lists_results_as_cards_that_open_the_page(): void
    {
        $this->setUpWiki();
        $page = $this->page('Escalation process', '<h2>Timings</h2><p>Ring the on-call within ten minutes.</p>');
        $this->page('Handover notes', '<p>Write it down.</p>');
        $this->save()->assertOk();

        $response = $this->actingAs($this->owner)
            ->get("/wiki/collections/{$this->collection->id}/preview?q=escalation");

        $response->assertOk();
        $response->assertSee('1 result for “escalation”', false);
        // Each card links to the page it stands for.
        $response->assertSee('preview?page='.$page->id, false);

        /* Not assertDontSee on the other title: the navigation lists every page in the
           collection whatever is being searched, so its name is on the screen either way. The
           count is what says the search discriminated. */
        $results = app(WikiReader::class)->search(
            app(WikiReader::class)->sections($this->collection->fresh()), 'escalation'
        );

        $this->assertSame(['Escalation process'], array_map(fn ($r) => $r['page']->title, $results));
    }

    public function test_the_results_page_reads_the_documents_not_only_their_names(): void
    {
        $this->setUpWiki();
        $page = $this->page('Escalation process', '<p>Ring the on-call within ten minutes.</p>');
        $this->save()->assertOk();

        // The word appears nowhere in the title — this is the whole reason the results page
        // exists beside the header dropdown, which can only see names.
        $this->actingAs($this->owner)
            ->get("/wiki/collections/{$this->collection->id}/preview?q=on-call")
            ->assertOk()
            ->assertSee('Escalation process')
            // And the card shows the phrase in its surroundings, so it says why it matched.
            ->assertSee('Ring the on-call within ten minutes.');
    }

    public function test_a_search_that_finds_nothing_says_so(): void
    {
        $this->setUpWiki();
        $this->page('Escalation process');
        $this->save()->assertOk();

        // An empty column reads as a search that broke.
        $this->actingAs($this->owner)
            ->get("/wiki/collections/{$this->collection->id}/preview?q=zzzznothing")
            ->assertOk()
            ->assertSee('0 results for “zzzznothing”', false)
            ->assertSee('Nothing in this collection matches that.');
    }

    public function test_the_results_page_replaces_the_document_and_keeps_the_way_back(): void
    {
        $this->setUpWiki();
        $page = $this->page('Escalation process', '<p>Ring the on-call.</p>');
        $this->save()->assertOk();

        $this->actingAs($this->owner)
            ->get("/wiki/collections/{$this->collection->id}/preview?page={$page->id}&q=escalation")
            ->assertOk()
            ->assertSee('1 result for “escalation”', false)
            // The document itself is not rendered underneath the results.
            ->assertDontSee('wi-rich', false)
            ->assertSee('Back to Product Knowledge Base');
    }

    public function test_a_collection_without_a_cover_has_no_header_search(): void
    {
        $this->setUpWiki();
        $page = $this->page('Escalation process');

        // The switch describes a front door. A collection that has not configured one has not
        // configured this either — the same rule alignment and the contents column follow.
        $this->actingAs($this->owner)
            ->get("/wiki/collections/{$this->collection->id}/preview?page={$page->id}")
            ->assertOk()
            ->assertDontSee('id="wiki-search"', false);
    }

    public function test_the_contents_column_answers_to_the_on_this_page_switch(): void
    {
        $this->setUpWiki();
        $page = $this->page('Escalation process', '<h1>Escalating</h1><p>a</p><h2>Timings</h2><p>b</p>');

        $this->save(['on_this_page_enabled' => true])->assertOk();
        $this->actingAs($this->owner)
            ->get("/wiki/collections/{$this->collection->id}/preview?page={$page->id}")
            ->assertOk()->assertSee('On this page');

        $this->save(['on_this_page_enabled' => false])->assertOk();
        $this->actingAs($this->owner)
            ->get("/wiki/collections/{$this->collection->id}/preview?page={$page->id}")
            ->assertOk()->assertDontSee('On this page');
    }
}
