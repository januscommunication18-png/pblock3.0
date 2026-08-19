<?php

namespace Tests\Feature\HelpCenter;

use App\Models\HelpCenterInbox;
use App\Models\HelpCenterSpace;

/**
 * Enablement, routing, and the first-run wizard
 * (docs/features/help-center.md, acceptance criteria 1–7).
 */
class OnboardingTest extends HelpCenterTestCase
{
    // ---- enablement & routing ------------------------------------------------------------

    public function test_a_workspace_without_the_app_gets_404_everywhere(): void
    {
        $this->setUpHelpCenter(enabled: false);

        // Criterion 1. Every door, not just the front one — an area that is off should not be
        // reachable by knowing a deeper URL.
        foreach (['/help-center', '/help-center/setup', '/help-center/conversations', '/help-center/inboxes'] as $url) {
            $this->actingAs($this->owner)->get($url)->assertNotFound();
        }
    }

    public function test_the_rail_entry_is_absent_when_the_app_is_off(): void
    {
        $this->setUpHelpCenter(enabled: false);

        $this->actingAs($this->owner)->get('/projects')->assertOk()->assertDontSee('/help-center');
    }

    public function test_the_rail_entry_appears_when_the_app_is_on(): void
    {
        // Criterion 2.
        $this->setUpHelpCenter();

        $this->actingAs($this->owner)->get('/projects')->assertOk()->assertSee('/help-center');
    }

    // ---- the wizard ----------------------------------------------------------------------

    public function test_a_workspace_with_no_space_is_sent_to_the_wizard(): void
    {
        $this->setUpHelpCenter();

        // Criterion 4: onboarding INSTEAD of an empty Help Center (§1).
        $this->actingAs($this->owner)->get('/help-center')->assertRedirect('/help-center/setup');

        $this->actingAs($this->owner)->get('/help-center/setup')
            ->assertOk()
            ->assertSee('Set up your Help Center');
    }

    public function test_the_wizard_resumes_at_step_two_when_a_space_exists(): void
    {
        $this->setUpHelpCenter();
        $this->space();

        // Criterion 5. The state is derived, so an abandoned run picks up where it stopped
        // rather than starting again (HC-D3).
        $this->actingAs($this->owner)->get('/help-center/setup')
            ->assertOk()
            ->assertSee('&quot;step&quot;:2', false);
    }

    public function test_the_wizard_resumes_at_step_three_when_an_inbox_is_unfinished(): void
    {
        $this->setUpHelpCenter();
        $this->inbox(attributes: ['setup_completed_at' => null]);

        $this->actingAs($this->owner)->get('/help-center/setup')
            ->assertOk()
            ->assertSee('&quot;step&quot;:3', false);
    }

    public function test_a_finished_workspace_never_sees_the_wizard_again(): void
    {
        $this->setUpHelpCenter();
        $this->inbox();

        // Criterion 6, both directions: the Help Center opens, and the wizard's own URL
        // refuses to reopen a run that is done.
        $this->actingAs($this->owner)->get('/help-center')->assertOk()->assertSee('Overview');
        $this->actingAs($this->owner)->get('/help-center/setup')->assertRedirect('/help-center');
    }

    public function test_the_wizard_creates_nothing_on_its_own(): void
    {
        $this->setUpHelpCenter();

        // Criterion 7: Cancel Setup is a link away, and merely LOOKING at the wizard must not
        // provision anything — a screen that creates a row when it is opened cannot be
        // abandoned.
        $this->actingAs($this->owner)->get('/help-center/setup')->assertOk();

        $this->assertSame(0, HelpCenterSpace::query()->count());
        $this->assertSame(0, HelpCenterInbox::query()->count());
    }

    // ---- the whole flow ------------------------------------------------------------------

    public function test_the_three_steps_complete_a_help_center(): void
    {
        $this->setUpHelpCenter();

        $space = $this->actingAs($this->owner)->postJson('/help-center/setup/space', [
            'name' => 'Customer Support',
            'description' => 'Product questions and account support.',
            'types' => ['Customer Support', 'VIP Support'],
            'lead_user_id' => $this->owner->id,
        ])->assertOk()->assertJsonPath('step', 2)->json('space');

        $this->assertSame('Customer Support', $space['name']);

        $inbox = $this->actingAs($this->owner)->postJson('/help-center/setup/inbox', [
            'name' => 'General Support',
            'addresses' => [
                ['email' => 'support@company.com', 'name' => 'Company Support'],
                ['email' => 'help@company.com', 'name' => null],
            ],
        ])->assertOk()->assertJsonPath('step', 3)->json('inbox');

        $this->assertCount(2, $inbox['addresses']);
        // §8: the inbound address is generated, not asked for.
        $this->assertMatchesRegularExpression(
            '/^inbox-[0-9a-z]{8}@inbound\.projectblock\.app$/',
            $inbox['inbound_address'],
        );

        // Criterion 23 — the summary of §12.
        $summary = $this->actingAs($this->owner)->postJson('/help-center/setup/complete')
            ->assertOk()->assertJsonPath('step', 4)->json('summary');

        $this->assertSame('Customer Support', $summary['space']['name']);
        $this->assertSame('General Support', $summary['inbox']['name']);
        $this->assertSame($this->owner->id, $summary['space']['lead']['id']);

        $this->assertNotNull(HelpCenterInbox::query()->first()->setup_completed_at);

        // And the Help Center now opens instead of the wizard.
        $this->actingAs($this->owner)->get('/help-center')->assertOk();
    }

    public function test_completing_setup_twice_does_not_move_the_timestamp(): void
    {
        $this->setUpHelpCenter();
        $inbox = $this->inbox(attributes: ['setup_completed_at' => null]);

        $this->actingAs($this->owner)->postJson('/help-center/setup/complete')->assertOk();
        $first = $inbox->fresh()->setup_completed_at;

        // Idempotent, or "when was this set up?" quietly becomes "when did somebody last look
        // at the instructions?".
        $this->travel(2)->minutes();
        $this->actingAs($this->owner)->postJson('/help-center/setup/complete')->assertOk();

        $this->assertEquals($first, $inbox->fresh()->setup_completed_at);
    }

    public function test_the_check_address_endpoint_answers_the_add_button(): void
    {
        $this->setUpHelpCenter();
        $inbox = $this->inbox();

        $this->actingAs($this->owner)
            ->postJson('/help-center/setup/check-address', ['email' => 'new@company.com'])
            ->assertOk()->assertJsonPath('ok', true);

        $this->actingAs($this->owner)
            ->postJson('/help-center/setup/check-address', ['email' => 'not-an-email'])
            ->assertStatus(422);

        $inbox->emailAddresses()->create([
            'tenant_id' => $this->workspace->id, 'email' => 'taken@company.com',
        ]);

        // §7's exact wording, at the moment the address is added.
        $this->actingAs($this->owner)
            ->postJson('/help-center/setup/check-address', ['email' => 'TAKEN@company.com'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This email address is already connected to another Inbox.');
    }
}
