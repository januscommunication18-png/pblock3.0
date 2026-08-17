<?php

namespace Tests\Feature\Wiki;

use App\Models\User;
use App\Models\WikiCollection;
use App\Services\WorkspaceApps;
use App\Services\WorkspaceCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Wiki collections (docs/features/wiki.md) — creating them.
 *
 * A collection is the unit the whole Wiki hangs off: pages live in one, permissions follow it,
 * and the sidebar is a list of them.
 */
class CollectionsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private function setUpWiki(bool $enabled = true): void
    {
        $this->owner = User::factory()->create(['full_name' => 'Rohit', 'email' => 'owner@example.com']);

        $workspace = app(WorkspaceCreator::class)->create($this->owner, [
            'name' => 'Acme Inc', 'slug' => 'acme-inc', 'company_size' => '2-10',
            'apps' => $enabled ? ['projects', 'wiki'] : ['projects'],
        ]);

        if ($enabled) {
            app(WorkspaceApps::class)->sync($workspace, ['wiki']);
        }

        $this->owner = $this->owner->fresh();
        tenancy()->initialize($workspace);
    }

    /** @return TestResponse */
    private function create(array $overrides = [])
    {
        return $this->actingAs($this->owner)->postJson('/wiki/collections', array_merge([
            'name' => 'Engineering',
            'description' => 'Architecture, APIs and deployment guides.',
            'visibility' => 'public',
        ], $overrides));
    }

    public function test_a_collection_can_be_created(): void
    {
        $this->setUpWiki();

        $this->create()->assertOk()->assertJsonPath('collections.0.name', 'Engineering');

        $collection = WikiCollection::query()->firstOrFail();

        $this->assertSame('Engineering', $collection->name);
        $this->assertSame('public', $collection->visibility);
        $this->assertSame($this->owner->id, $collection->created_by);
        $this->assertNull($collection->archived_at);
    }

    public function test_a_collection_can_be_private(): void
    {
        $this->setUpWiki();

        $this->create(['visibility' => 'private'])->assertOk();

        $this->assertTrue(WikiCollection::query()->firstOrFail()->isPrivate());
    }

    public function test_a_collection_needs_a_name(): void
    {
        $this->setUpWiki();

        $this->create(['name' => '   '])->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_an_unknown_visibility_is_refused(): void
    {
        $this->setUpWiki();

        // Access control is the point of this field; a value nobody enforces is worse than
        // no field at all.
        $this->create(['visibility' => 'everyone'])->assertStatus(422)
            ->assertJsonValidationErrors('visibility');
    }

    public function test_new_collections_are_appended_rather_than_jumping_to_the_top(): void
    {
        $this->setUpWiki();

        $this->create(['name' => 'First'])->assertOk();
        $this->create(['name' => 'Second'])->assertOk();

        // §"Control the order of your knowledge": documentation follows a sequence somebody
        // chose, so a new collection must not displace it.
        $this->assertSame(
            ['First', 'Second'],
            WikiCollection::query()->orderBy('position')->pluck('name')->all(),
        );
    }

    public function test_collections_cannot_be_created_when_wiki_is_off(): void
    {
        $this->setUpWiki(enabled: false);

        // Hidden from the navigation is not the same as unreachable.
        $this->create()->assertNotFound();

        $this->assertSame(0, WikiCollection::query()->count());
    }

    public function test_the_wiki_home_and_sidebar_both_show_the_collections(): void
    {
        $this->setUpWiki();
        $this->create(['name' => 'People & Culture'])->assertOk();

        $response = $this->actingAs($this->owner)->get('/wiki')->assertOk();

        // The sidebar is server-rendered and the Home list is not, so a collection that only
        // reached one of them would look like a half-created thing.
        $response->assertSee('People &amp; Culture', false);
        $this->assertSame(
            'People & Culture',
            $response->viewData('bootstrap')['collections'][0]['name'],
        );
    }

    public function test_a_collection_belongs_to_its_workspace_and_no_other(): void
    {
        $this->setUpWiki();
        $this->create(['name' => 'Ours'])->assertOk();

        $intruder = User::factory()->create(['email' => 'other@example.com']);
        $other = app(WorkspaceCreator::class)->create($intruder, [
            'name' => 'Other Co', 'slug' => 'other-co', 'company_size' => '2-10',
            'apps' => ['projects', 'wiki'],
        ]);

        // Tenant isolation (CLAUDE.md §7): another workspace sees none of it.
        $this->assertSame(0, $other->run(fn () => WikiCollection::query()->count()));
    }
}
