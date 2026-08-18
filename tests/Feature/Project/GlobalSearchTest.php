<?php

namespace Tests\Feature\Project;

use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\WikiCollection;
use App\Models\WikiPage;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Global search — docs/features/global-search.md (slice 1).
 *
 * The permission cases carry the weight here. §12 says restricted content must not appear in
 * results at all, and search is the one screen that reaches across every project at once, so a
 * scoping mistake here leaks further than the same mistake anywhere else.
 */
class GlobalSearchTest extends ProjectTestCase
{
    use RefreshDatabase;

    private function item($actor, Project $project, string $title): array
    {
        return $this->actingAs($actor)
            ->postJson(route('projects.work-items.store', $project), ['title' => $title])
            ->assertStatus(201)->json('item');
    }

    private function search($actor, string $q): array
    {
        return $this->actingAs($actor)->getJson(route('search', ['q' => $q]))->assertOk()->json();
    }

    /** @return array<int, string> every result title, flattened across groups */
    private function titles(array $payload): array
    {
        return collect($payload['groups'] ?? [])
            ->flatMap(fn ($g) => collect($g['results'])->pluck('title'))
            ->all();
    }

    public function test_it_finds_a_work_item_and_shapes_the_result_for_the_card(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $this->item($owner, $project, 'Login validation issue');

        $payload = $this->search($owner, 'login');

        $this->assertSame('work_items', $payload['groups'][0]['key']);
        $this->assertFalse($payload['error']);

        $result = $payload['groups'][0]['results'][0];
        $this->assertSame('Login validation issue', $result['title']);
        $this->assertSame($project->name, $result['project']);
        $this->assertNotNull($result['identifier']);
        // §11.1 — the result must land on the list with the drawer already opening.
        $this->assertStringContainsString('/work-items?item='.$result['id'].'&tab=all', $result['url']);
    }

    public function test_a_query_under_two_characters_searches_nothing(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $this->item($owner, $project, 'Login validation issue');

        $this->assertSame([], $this->search($owner, 'l')['groups']);
    }

    /**
     * The core §12 case: a workspace member who is not a member of the project.
     *
     * ProjectPolicy::view() requires an explicit project membership for anyone who is not a
     * workspace owner/admin — visibility does not open a project up. Search must agree with
     * that, or it becomes a way to read projects you were never added to.
     */
    public function test_a_member_of_no_project_finds_nothing(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $this->item($owner, $project, 'Login validation issue');

        $outsider = $this->member($ws, 'member', 'outsider@example.com');

        $this->assertSame([], $this->titles($this->search($outsider, 'login')));
    }

    public function test_another_workspaces_items_are_never_returned(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $this->item($owner, $project, 'Login validation issue');

        // A second workspace with its own owner and its own similarly-named item.
        [$other, $otherWs] = $this->owner('other-co');
        $otherProject = $this->makeProject($other, $otherWs, ['identifier' => 'OTH']);
        $this->item($other, $otherProject, 'Login validation issue');

        $found = $this->search($other, 'login');

        // One result, and it is theirs: the tenant filter is doing the work, not luck.
        $this->assertCount(1, $found['groups'][0]['results']);
        $this->assertSame($otherProject->name, $found['groups'][0]['results'][0]['project']);
    }

    /**
     * §10's "assigned work items only" projects (decision G6).
     *
     * The constraint is applied while hydrating rather than as an engine filter, so this is the
     * case that proves the hydration constraint actually runs — without it the item would come
     * back, because the engine happily matched it.
     */
    public function test_in_an_assigned_only_project_a_member_finds_only_their_own_items(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);

        $mine = $this->item($owner, $project, 'Login mine');
        $theirs = $this->item($owner, $project, 'Login theirs');

        $member = $this->member($ws, 'member', 'contributor@example.com');
        $ws->run(fn () => ProjectMember::create([
            'project_id' => $project->id, 'user_id' => $member->id, 'role' => 'contributor',
        ]));
        $ws->run(fn () => WorkItem::withoutGlobalScopes()->find($mine['id'])
            ->assignees()->syncWithoutDetaching([$member->id]));

        $ws->run(fn () => $project->forceFill(['work_item_view' => 'assigned'])->save());

        $titles = $this->titles($this->search($member, 'login'));

        $this->assertContains('Login mine', $titles);
        $this->assertNotContains('Login theirs', $titles, 'an assigned-only project must not leak items the member is not on');
        $this->assertNotNull($theirs['id']);
    }

    public function test_archived_items_are_not_searchable(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);
        $item = $this->item($owner, $project, 'Login validation issue');

        $ws->run(fn () => WorkItem::withoutGlobalScopes()->find($item['id'])
            ->forceFill(['archived_at' => now()])->save());

        $this->assertSame([], $this->titles($this->search($owner, 'login')));
    }

    /**
     * §6.2 — typing an identifier in full is naming a record, not describing one, so that item
     * outranks everything the text matched.
     *
     * The identifier is set explicitly here because this app's identifiers are plain sequence
     * numbers (1, 2, 3 …), not the `PB-168` form the requirements document assumes. A freshly
     * created item is therefore "1", which is below the two-character minimum and could never
     * reach the ranking code this test exists to cover.
     */
    public function test_an_exact_identifier_match_ranks_first(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws);

        $target = $this->item($owner, $project, 'Something unrelated to the query');
        $this->item($owner, $project, 'Login note 168 mentions the number');

        $ws->run(fn () => WorkItem::withoutGlobalScopes()->find($target['id'])
            ->forceFill(['identifier' => 168])->save());

        $titles = $this->titles($this->search($owner, '168'));

        $this->assertSame('Something unrelated to the query', $titles[0] ?? null,
            'an exact identifier match must outrank an item that merely mentions the number');
    }

    public function test_a_guest_cannot_search(): void
    {
        $this->getJson(route('search', ['q' => 'login']))->assertUnauthorized();
    }

    // ---------- Wiki (§5) ----------

    private function collection($ws, $owner, string $name, string $visibility = 'public'): WikiCollection
    {
        return $ws->run(fn () => WikiCollection::create([
            'tenant_id' => $ws->id, 'name' => $name, 'visibility' => $visibility,
            'created_by' => $owner->id, 'position' => 1,
        ]));
    }

    private function page($ws, $owner, WikiCollection $collection, string $title, ?string $content = null): WikiPage
    {
        return $ws->run(fn () => WikiPage::create([
            'tenant_id' => $ws->id, 'wiki_collection_id' => $collection->id,
            'title' => $title, 'content' => $content,
            'created_by' => $owner->id, 'updated_by' => $owner->id, 'position' => 1,
        ]));
    }

    public function test_it_finds_wiki_pages_and_collections(): void
    {
        [$owner, $ws] = $this->owner();
        $collection = $this->collection($ws, $owner, 'Login Handbook');
        $this->page($ws, $owner, $collection, 'Login troubleshooting');

        $payload = $this->search($owner, 'login');
        $wiki = collect($payload['groups'])->firstWhere('key', 'wiki');

        $this->assertNotNull($wiki, 'a Wiki group must appear alongside Work Items');

        $titles = collect($wiki['results'])->pluck('title')->all();
        $this->assertContains('Login troubleshooting', $titles);
        $this->assertContains('Login Handbook', $titles);

        $page = collect($wiki['results'])->firstWhere('type', 'wiki_page');
        // The card's context line names the collection the page lives in (§8.2).
        $this->assertSame('Login Handbook', $page['context']);
        $this->assertStringContainsString('?page='.$page['id'], $page['url']);
    }

    public function test_page_body_text_is_searchable(): void
    {
        [$owner, $ws] = $this->owner();
        $collection = $this->collection($ws, $owner, 'Handbook');
        $this->page($ws, $owner, $collection, 'Untitled note', '<p>Everything about <b>authentication</b> here.</p>');

        $wiki = collect($this->search($owner, 'authentication')['groups'])->firstWhere('key', 'wiki');

        $this->assertNotNull($wiki, 'page content must be searchable, not just titles');
        $this->assertSame('Untitled note', $wiki['results'][0]['title']);
        // The snippet is plain text — indexing the markup would match documents on their tags.
        $this->assertStringNotContainsString('<b>', (string) $wiki['results'][0]['snippet']);
    }

    /**
     * §12 for the wiki: a private collection is invisible to somebody never invited to it.
     *
     * Its NAME leaking would be enough to break the promise the visibility dialog makes, so
     * this asserts on the whole payload rather than just the page rows.
     */
    public function test_a_private_collection_is_invisible_to_a_non_member(): void
    {
        [$owner, $ws] = $this->owner();
        $secret = $this->collection($ws, $owner, 'Login Secrets', 'private');
        $this->page($ws, $owner, $secret, 'Login master keys');

        $outsider = $this->member($ws, 'member', 'outsider@example.com');

        $payload = $this->search($outsider, 'login');
        $body = json_encode($payload);

        $this->assertStringNotContainsString('Login master keys', $body);
        $this->assertStringNotContainsString('Login Secrets', $body);
    }

    public function test_archived_wiki_pages_are_not_searchable(): void
    {
        [$owner, $ws] = $this->owner();
        $collection = $this->collection($ws, $owner, 'Handbook');
        $page = $this->page($ws, $owner, $collection, 'Login troubleshooting');

        $ws->run(fn () => $page->forceFill(['archived_at' => now()])->save());

        $wiki = collect($this->search($owner, 'login troubleshooting')['groups'])->firstWhere('key', 'wiki');
        $titles = $wiki ? collect($wiki['results'])->pluck('title')->all() : [];

        $this->assertNotContains('Login troubleshooting', $titles);
    }
}
