<?php

namespace Tests\Feature\HelpCenter;

use App\Models\HelpCenterSpace;

/**
 * Who may configure what (docs/features/help-center.md §19;
 * acceptance criteria 3 and 26).
 *
 * Every check here is made against the API rather than the screen. §19's rules are about what
 * somebody may DO, and a hidden button is presentation — the endpoint is the authority.
 */
class PermissionsTest extends HelpCenterTestCase
{
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Billing Support',
            'types' => ['Billing'],
            'lead_user_id' => $this->owner->id,
        ], $overrides);
    }

    // ---- Agent: sees the Help Center, configures nothing ----------------------------------

    public function test_enabling_the_app_grants_no_configuration_rights(): void
    {
        // Criterion 3.
        $this->setUpHelpCenter();
        $this->inbox();
        $agent = $this->member();

        $this->actingAs($agent)->get('/help-center')->assertOk();

        $this->actingAs($agent)->postJson('/help-center/spaces', $this->payload())->assertForbidden();
    }

    public function test_an_agent_cannot_create_an_inbox_or_add_an_address(): void
    {
        // Criterion 26.
        $this->setUpHelpCenter();
        $space = $this->space();
        $inbox = $this->inbox($space);
        $agent = $this->member();

        $this->actingAs($agent)
            ->postJson("/help-center/spaces/{$space->id}/inboxes", ['name' => 'Sneaky'])
            ->assertForbidden();

        $this->actingAs($agent)
            ->postJson("/help-center/inboxes/{$inbox->id}/addresses", ['email' => 'sneaky@company.com'])
            ->assertForbidden();

        $address = $inbox->emailAddresses()->create([
            'tenant_id' => $this->workspace->id, 'email' => 'support@company.com',
        ]);

        $this->actingAs($agent)
            ->deleteJson("/help-center/inboxes/{$inbox->id}/addresses/{$address->id}")
            ->assertForbidden();
    }

    public function test_an_agent_can_read_the_spaces_and_their_views(): void
    {
        $this->setUpHelpCenter();
        $space = $this->space();
        $this->inbox($space);
        $agent = $this->member();

        $this->actingAs($agent)->get("/help-center/spaces/{$space->id}/unassigned")->assertOk();
        $this->actingAs($agent)->get('/help-center/inboxes')->assertOk();
        $this->actingAs($agent)->get('/help-center/conversations')->assertOk();
    }

    // ---- Space Lead: manages their own Space, and only theirs -----------------------------

    public function test_a_space_lead_manages_their_own_space(): void
    {
        $this->setUpHelpCenter();
        $lead = $this->member('lead@example.com');
        $space = $this->space(['lead_user_id' => $lead->id]);
        $inbox = $this->inbox($space);

        // §19's Space Lead: create/edit Inboxes, manage members, view Inbox configuration.
        $this->actingAs($lead)
            ->postJson("/help-center/spaces/{$space->id}/inboxes", ['name' => 'Escalations'])
            ->assertOk();

        $this->actingAs($lead)
            ->postJson("/help-center/inboxes/{$inbox->id}/addresses", ['email' => 'lead@company.com'])
            ->assertOk();
    }

    public function test_a_space_lead_cannot_reach_into_a_space_they_do_not_lead(): void
    {
        $this->setUpHelpCenter();
        $lead = $this->member('lead@example.com');

        $theirs = $this->space(['name' => 'Their Space', 'lead_user_id' => $lead->id]);
        $this->inbox($theirs);

        $others = $this->space(['name' => 'Someone Elses', 'lead_user_id' => $this->owner->id, 'position' => 2]);
        $othersInbox = $this->inbox($others, ['name' => 'Other Inbox', 'inbound_id' => 'c4d5e6f7', 'position' => 2]);

        // Leading one Space is responsibility for that Space, not a key to the next one.
        $this->actingAs($lead)
            ->postJson("/help-center/spaces/{$others->id}/inboxes", ['name' => 'Sneaky'])
            ->assertForbidden();

        $this->actingAs($lead)
            ->postJson("/help-center/inboxes/{$othersInbox->id}/addresses", ['email' => 'sneaky@company.com'])
            ->assertForbidden();
    }

    public function test_a_space_lead_cannot_create_new_spaces(): void
    {
        $this->setUpHelpCenter();
        $lead = $this->member('lead@example.com');
        $space = $this->space(['lead_user_id' => $lead->id]);
        $this->inbox($space);

        // §19 gives "Create Spaces" to the Help Desk Admin and not to the Space Lead: adding
        // Spaces to somebody else's workspace is workspace administration.
        $this->actingAs($lead)->postJson('/help-center/spaces', $this->payload())->assertForbidden();
    }

    // ---- Help Desk Admin ------------------------------------------------------------------

    public function test_a_workspace_admin_manages_every_space(): void
    {
        $this->setUpHelpCenter();
        $admin = $this->member('admin@example.com', 'admin');
        $lead = $this->member('lead@example.com');

        $space = $this->space(['lead_user_id' => $lead->id]);
        $inbox = $this->inbox($space);

        $this->actingAs($admin)->postJson('/help-center/spaces', $this->payload())->assertOk();
        $this->actingAs($admin)
            ->postJson("/help-center/inboxes/{$inbox->id}/addresses", ['email' => 'admin@company.com'])
            ->assertOk();
    }

    // ---- the wizard ------------------------------------------------------------------------

    public function test_an_agent_cannot_run_the_setup_wizard(): void
    {
        $this->setUpHelpCenter();
        $agent = $this->member();

        // The screen is reachable — it explains who can finish setup — but the act is not.
        $this->actingAs($agent)->get('/help-center/setup')->assertOk();

        $this->actingAs($agent)
            ->postJson('/help-center/setup/space', $this->payload())
            ->assertForbidden();

        $this->assertSame(0, HelpCenterSpace::query()->count());
    }

    public function test_the_wizard_refuses_to_create_a_second_space_once_setup_is_done(): void
    {
        $this->setUpHelpCenter();
        $this->inbox();

        // Two tabs left open on step 1 would otherwise create a Space the wizard then ignores.
        $this->actingAs($this->owner)
            ->postJson('/help-center/setup/space', $this->payload())
            ->assertStatus(409);
    }

    // ---- signed-out ------------------------------------------------------------------------

    /**
     * A signed-out caller gets nothing.
     *
     * Only the JSON side is asserted, and that is a statement about the APPLICATION rather than
     * about the Help Center: `Authenticate` redirects browsers to `route('login')`, this
     * application names that route `signin`, and nothing configures `redirectGuestsTo` — so a
     * signed-out GET to any authenticated route raises RouteNotFoundException and answers 500.
     * It is reproducible on /projects, /wiki, /your-work and /settings/general, and it predates
     * this module.
     *
     * Asserting the 500 here would pin a bug in place; asserting a redirect would fail for a
     * reason that has nothing to do with the Help Center. So this covers what IS this module's
     * to guarantee — the endpoints refuse an unauthenticated caller — and the redirect belongs
     * to whoever fixes the auth layer.
     */
    public function test_a_signed_out_caller_is_refused(): void
    {
        $this->setUpHelpCenter();
        $this->inbox();

        $this->postJson('/help-center/spaces', $this->payload())->assertUnauthorized();
        $this->getJson('/help-center')->assertUnauthorized();
        $this->postJson('/help-center/setup/check-address', ['email' => 'x@company.com'])->assertUnauthorized();
    }
}
