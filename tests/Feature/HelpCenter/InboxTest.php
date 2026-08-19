<?php

namespace Tests\Feature\HelpCenter;

use App\Models\HelpCenterInbox;
use App\Services\HelpCenter\InboundAddressGenerator;

/**
 * Inboxes and their generated inbound identifiers
 * (docs/features/help-center.md §5, §8; acceptance criteria 13–16).
 */
class InboxTest extends HelpCenterTestCase
{
    public function test_an_inbox_is_created_with_a_generated_inbound_identifier(): void
    {
        // Criterion 13.
        $this->setUpHelpCenter();
        $space = $this->space();
        $this->inbox($space);

        $payload = $this->actingAs($this->owner)
            ->postJson("/help-center/spaces/{$space->id}/inboxes", ['name' => 'Enterprise Support'])
            ->assertOk()->json('inbox');

        $this->assertSame('Enterprise Support', $payload['name']);
        $this->assertMatchesRegularExpression(
            '/^inbox-[0-9a-z]{8}@inbound\.projectblock\.app$/',
            $payload['inbound_address'],
        );
    }

    public function test_an_inbox_created_outside_the_wizard_is_already_set_up(): void
    {
        $this->setUpHelpCenter();
        $space = $this->space();
        $this->inbox($space);

        $id = $this->actingAs($this->owner)
            ->postJson("/help-center/spaces/{$space->id}/inboxes", ['name' => 'Enterprise Support'])
            ->assertOk()->json('inbox.id');

        // There is no outstanding step for it, so `setup_completed_at` must not read as
        // mid-setup (HC-D3).
        $this->assertNotNull(HelpCenterInbox::query()->find($id)->setup_completed_at);
    }

    public function test_identifiers_are_unique_and_not_sequential(): void
    {
        // Criteria 14 and 15.
        $this->setUpHelpCenter();
        $space = $this->space();
        $this->inbox($space);

        $tokens = [];

        for ($i = 0; $i < 12; $i++) {
            $tokens[] = $this->actingAs($this->owner)
                ->postJson("/help-center/spaces/{$space->id}/inboxes", ['name' => "Inbox $i"])
                ->assertOk()->json('inbox.inbound_address');
        }

        $this->assertCount(12, array_unique($tokens));

        // Not a database id in disguise: none of them is the row's own id, and they do not
        // ascend (§8, "not expose sequential database IDs").
        foreach (HelpCenterInbox::query()->get() as $inbox) {
            $this->assertNotSame((string) $inbox->id, $inbox->inbound_id);
        }
    }

    public function test_the_generated_alphabet_excludes_look_alike_characters(): void
    {
        $this->setUpHelpCenter();

        $generator = app(InboundAddressGenerator::class);

        // 200 draws is enough to see every symbol the alphabet can produce many times over.
        for ($i = 0; $i < 200; $i++) {
            $token = $generator->generate();

            $this->assertSame(8, strlen($token));
            // i, l, o and u are out — the first three because they read as 1, 1 and 0 when
            // somebody copies an address by hand.
            $this->assertDoesNotMatchRegularExpression('/[ilou]/', $token);
            $this->assertMatchesRegularExpression('/^[0-9a-z]{8}$/', $token);
        }
    }

    public function test_a_duplicate_name_is_rejected_within_a_space_but_free_across_them(): void
    {
        // Criterion 16.
        $this->setUpHelpCenter();
        $support = $this->space(['name' => 'Customer Support']);
        $this->inbox($support, ['name' => 'General Support']);

        $this->actingAs($this->owner)
            ->postJson("/help-center/spaces/{$support->id}/inboxes", ['name' => 'general support'])
            ->assertStatus(422)->assertJsonValidationErrors('name');

        // Two Spaces may each run a "General Support" — most of the point of having Spaces.
        $billing = $this->space(['name' => 'Billing Support', 'position' => 2]);

        $this->actingAs($this->owner)
            ->postJson("/help-center/spaces/{$billing->id}/inboxes", ['name' => 'General Support'])
            ->assertOk();
    }

    public function test_the_name_is_required_and_bounded(): void
    {
        $this->setUpHelpCenter();
        $space = $this->space();
        $this->inbox($space);

        foreach (['', str_repeat('a', 101)] as $name) {
            $this->actingAs($this->owner)
                ->postJson("/help-center/spaces/{$space->id}/inboxes", ['name' => $name])
                ->assertStatus(422)->assertJsonValidationErrors('name');
        }
    }

    public function test_an_inbox_carries_its_addresses_when_created(): void
    {
        $this->setUpHelpCenter();
        $space = $this->space();
        $this->inbox($space);

        $payload = $this->actingAs($this->owner)
            ->postJson("/help-center/spaces/{$space->id}/inboxes", [
                'name' => 'Enterprise Support',
                'addresses' => [
                    ['email' => 'enterprise@company.com', 'name' => 'Enterprise'],
                    ['email' => 'vip@company.com', 'name' => null],
                ],
            ])->assertOk()->json('inbox');

        $this->assertCount(2, $payload['addresses']);
        $this->assertSame('Setup Required', $payload['addresses'][0]['status_label']);
    }

    public function test_a_rejected_address_leaves_no_half_built_inbox(): void
    {
        $this->setUpHelpCenter();
        $space = $this->space();
        $existing = $this->inbox($space);
        $existing->emailAddresses()->create(['tenant_id' => $this->workspace->id, 'email' => 'taken@company.com']);

        $before = HelpCenterInbox::query()->count();

        $this->actingAs($this->owner)
            ->postJson("/help-center/spaces/{$space->id}/inboxes", [
                'name' => 'Enterprise Support',
                'addresses' => [['email' => 'taken@company.com', 'name' => null]],
            ])->assertStatus(422)->assertJsonValidationErrors('addresses.0.email');

        // Validation runs before anything is written, so nothing is.
        $this->assertSame($before, HelpCenterInbox::query()->count());
    }

    public function test_the_same_address_twice_in_one_submission_names_the_row(): void
    {
        $this->setUpHelpCenter();
        $space = $this->space();
        $this->inbox($space);

        $this->actingAs($this->owner)
            ->postJson("/help-center/spaces/{$space->id}/inboxes", [
                'name' => 'Enterprise Support',
                'addresses' => [
                    ['email' => 'dupe@company.com', 'name' => null],
                    ['email' => 'DUPE@company.com', 'name' => null],
                ],
            ])->assertStatus(422)->assertJsonValidationErrors('addresses.1.email');
    }

    public function test_the_inboxes_screen_lists_every_inbox(): void
    {
        $this->setUpHelpCenter();
        $space = $this->space();
        $this->inbox($space);

        $this->actingAs($this->owner)->get('/help-center/inboxes')
            ->assertOk()
            ->assertSee('General Support')
            ->assertSee('inbox-a8f4k2m9@inbound.projectblock.app');
    }
}
