<?php

namespace Tests\Feature\HelpCenter;

use App\Models\HelpCenterSpace;
use App\Models\User;
use App\Models\WorkspaceMembership;

/**
 * Spaces — validation, multiplicity and isolation
 * (docs/features/help-center.md §3, §4; acceptance criteria 8–12).
 */
class SpaceTest extends HelpCenterTestCase
{
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Billing Support',
            'description' => 'Invoices, refunds and account questions.',
            'types' => ['Billing'],
            'lead_user_id' => $this->owner->id,
        ], $overrides);
    }

    public function test_a_space_is_created_and_appears_in_the_navigation(): void
    {
        // Criterion 8.
        $this->setUpHelpCenter();
        $this->inbox();

        $this->actingAs($this->owner)->postJson('/help-center/spaces', $this->payload())
            ->assertOk()
            ->assertJsonPath('space.name', 'Billing Support')
            ->assertJsonPath('space.type_label', 'Billing')
            ->assertJsonPath('space.types', ['Billing']);

        $this->actingAs($this->owner)->get('/help-center')->assertOk()->assertSee('Billing Support');
    }

    public function test_a_duplicate_name_is_rejected_whatever_its_casing(): void
    {
        // Criterion 9. "Billing" and "billing" are the same Space to anybody reading the nav.
        $this->setUpHelpCenter();
        $this->inbox();
        $this->space(['name' => 'Billing Support']);

        $this->actingAs($this->owner)->postJson('/help-center/spaces', $this->payload(['name' => 'BILLING support']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $this->assertSame(1, HelpCenterSpace::query()->where('name', 'Billing Support')->count());
    }

    public function test_the_same_name_is_free_in_another_workspace(): void
    {
        $this->setUpHelpCenter();
        $this->space(['name' => 'Billing Support']);

        $other = $this->otherWorkspace();

        // Tenancy working: one workspace's names do not constrain another's.
        tenancy()->initialize($other);
        $space = HelpCenterSpace::create([
            'tenant_id' => $other->id, 'name' => 'Billing Support', 'types' => ['Billing'],
            'lead_user_id' => $other->creator->id, 'created_by' => $other->creator->id,
        ]);
        tenancy()->initialize($this->workspace);

        $this->assertNotNull($space->id);
    }

    public function test_the_fields_are_validated(): void
    {
        // Criterion 10, in one place: each of these is a different way of not having said
        // what §3 requires.
        $this->setUpHelpCenter();
        $this->inbox();

        $cases = [
            'name' => ['name' => ''],
            'name.max' => ['name' => str_repeat('a', 101)],
            'types' => ['types' => []],
            'lead' => ['lead_user_id' => null],
            'description' => ['description' => str_repeat('a', 501)],
        ];

        foreach ($cases as $label => $override) {
            $field = str_contains($label, 'name') ? 'name'
                : ($label === 'lead' ? 'lead_user_id' : $label);

            $this->actingAs($this->owner)
                ->postJson('/help-center/spaces', $this->payload($override))
                ->assertStatus(422, "expected [$label] to be rejected")
                ->assertJsonValidationErrors($field);
        }
    }

    public function test_a_lead_must_be_an_active_non_guest_member(): void
    {
        $this->setUpHelpCenter();
        $this->inbox();

        // Somebody in a different workspace entirely.
        $stranger = User::factory()->create(['email' => 'stranger@example.com']);

        $this->actingAs($this->owner)
            ->postJson('/help-center/spaces', $this->payload(['lead_user_id' => $stranger->id]))
            ->assertStatus(422)->assertJsonValidationErrors('lead_user_id');

        // A guest IS in the workspace, and is still not somebody to make "primarily
        // responsible" for a Space (§3, §19).
        $guest = $this->member('guest@example.com', 'guest');

        $this->actingAs($this->owner)
            ->postJson('/help-center/spaces', $this->payload(['lead_user_id' => $guest->id]))
            ->assertStatus(422)->assertJsonValidationErrors('lead_user_id');

        // An ordinary member is fine.
        $mate = $this->member();

        $this->actingAs($this->owner)
            ->postJson('/help-center/spaces', $this->payload(['lead_user_id' => $mate->id]))
            ->assertOk();
    }

    public function test_a_deactivated_member_stops_being_eligible(): void
    {
        $this->setUpHelpCenter();
        $this->inbox();
        $mate = $this->member();

        WorkspaceMembership::query()
            ->where('workspace_id', $this->workspace->id)
            ->where('user_id', $mate->id)
            ->update(['status' => 'inactive']);

        $this->actingAs($this->owner)
            ->postJson('/help-center/spaces', $this->payload(['lead_user_id' => $mate->id]))
            ->assertStatus(422)->assertJsonValidationErrors('lead_user_id');
    }

    public function test_creating_a_second_space_does_not_restart_onboarding(): void
    {
        // Criterion 11 / §20 rule 12 — the thing §4 explicitly asks for.
        $this->setUpHelpCenter();
        $this->inbox();

        $this->actingAs($this->owner)->postJson('/help-center/spaces', $this->payload())->assertOk();

        $this->actingAs($this->owner)->get('/help-center')->assertOk();
        $this->actingAs($this->owner)->get('/help-center/setup')->assertRedirect('/help-center');
    }

    public function test_another_workspaces_space_is_not_reachable(): void
    {
        // Criterion 12.
        $this->setUpHelpCenter();
        $this->inbox();

        $other = $this->otherWorkspace();
        tenancy()->initialize($other);
        $theirs = HelpCenterSpace::create([
            'tenant_id' => $other->id, 'name' => 'Their Space', 'types' => ['Other'],
            'lead_user_id' => $other->creator->id, 'created_by' => $other->creator->id,
        ]);
        tenancy()->initialize($this->workspace);

        $this->actingAs($this->owner)->get("/help-center/spaces/{$theirs->id}")->assertNotFound();
        $this->actingAs($this->owner)->get("/help-center/spaces/{$theirs->id}/unassigned")->assertNotFound();
    }

    // ---- the system views (§16) -----------------------------------------------------------

    public function test_a_space_opens_on_its_first_system_view(): void
    {
        $this->setUpHelpCenter();
        $space = $this->space();
        $this->inbox($space);

        $this->actingAs($this->owner)->get("/help-center/spaces/{$space->id}")
            ->assertRedirect("/help-center/spaces/{$space->id}/unassigned");
    }

    public function test_every_system_view_renders_with_its_own_empty_state(): void
    {
        $this->setUpHelpCenter();
        $space = $this->space();
        $this->inbox($space);

        // §16 names these precisely — "Mine", not "Min"; "Drafts", not "Draft".
        $expected = [
            'unassigned' => 'Unassigned',
            'mine' => 'Mine',
            'drafts' => 'Drafts',
            'assigned' => 'Assigned',
            'closed' => 'Closed',
            'spam' => 'Spam',
        ];

        foreach ($expected as $key => $label) {
            $this->actingAs($this->owner)->get("/help-center/spaces/{$space->id}/{$key}")
                ->assertOk()
                ->assertSee($label)
                ->assertSee(config("help-center.space_views.$key.empty"));
        }
    }

    public function test_an_unknown_view_is_a_404(): void
    {
        $this->setUpHelpCenter();
        $space = $this->space();
        $this->inbox($space);

        // Enumerated by the route, so this never renders an empty list that looks like a view
        // with nothing in it.
        $this->actingAs($this->owner)->get("/help-center/spaces/{$space->id}/archived")->assertNotFound();
    }

    // ---- Space Types: free text, many per Space (§3, HC-D9) --------------------------------

    public function test_many_types_are_stored_in_the_order_they_were_added(): void
    {
        $this->setUpHelpCenter();
        $this->inbox();

        $types = ['Customer Support', 'Technical Support', 'VIP Support'];

        $this->actingAs($this->owner)
            ->postJson('/help-center/spaces', $this->payload(['types' => $types]))
            ->assertOk()
            ->assertJsonPath('space.types', $types);

        $this->assertSame($types, HelpCenterSpace::query()->where('name', 'Billing Support')->first()->types);
    }

    public function test_a_type_outside_the_suggestions_is_accepted(): void
    {
        // The suggestions are shortcuts, not a vocabulary — validating against them would put
        // the dropdown back (HC-D9).
        $this->setUpHelpCenter();
        $this->inbox();

        $this->actingAs($this->owner)
            ->postJson('/help-center/spaces', $this->payload(['types' => ['mainframe', 'Tier 3 Escalations']]))
            ->assertOk()
            ->assertJsonPath('space.types', ['mainframe', 'Tier 3 Escalations']);
    }

    public function test_types_are_trimmed_and_deduplicated_case_insensitively(): void
    {
        $this->setUpHelpCenter();
        $this->inbox();

        $this->actingAs($this->owner)
            ->postJson('/help-center/spaces', $this->payload([
                'types' => ['  Billing  ', 'billing', 'BILLING', 'Sales', '', '   '],
            ]))
            ->assertOk()
            // The FIRST spelling survives, blanks are dropped, and the count is what is stored.
            ->assertJsonPath('space.types', ['Billing', 'Sales']);
    }

    public function test_nine_types_that_dedupe_to_five_are_accepted(): void
    {
        // The cap counts what will be STORED, not what was typed — normalizing before the rules
        // run is what makes that true.
        $this->setUpHelpCenter();
        $this->inbox();

        $this->actingAs($this->owner)
            ->postJson('/help-center/spaces', $this->payload([
                'types' => ['a', 'A', 'b', 'B', 'c', 'C', 'd', 'D', 'e'],
            ]))
            ->assertOk()
            ->assertJsonPath('space.types', ['a', 'b', 'c', 'd', 'e']);
    }

    public function test_the_type_label_reads_them_as_one_line(): void
    {
        $this->setUpHelpCenter();
        $space = $this->space(['types' => ['Customer Support', 'VIP Support']]);
        $this->inbox($space);

        $this->assertSame('Customer Support, VIP Support', $space->typeLabel());

        // And the Space screen shows each as its own chip.
        $this->actingAs($this->owner)->get("/help-center/spaces/{$space->id}/unassigned")
            ->assertOk()->assertSee('Customer Support')->assertSee('VIP Support');
    }
}
