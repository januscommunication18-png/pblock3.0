<?php

namespace Tests\Feature\HelpCenter;

use App\Models\HelpCenterEmailAddress;
use App\Models\HelpCenterInbox;
use App\Models\HelpCenterSpace;

/**
 * Customer-facing addresses (docs/features/help-center.md §6, §7;
 * acceptance criteria 17–22).
 */
class EmailAddressTest extends HelpCenterTestCase
{
    public function test_an_address_is_normalized_before_it_is_stored(): void
    {
        // Criterion 17: trim, then lowercase (§7).
        $this->setUpHelpCenter();
        $inbox = $this->inbox();

        $this->actingAs($this->owner)
            ->postJson("/help-center/inboxes/{$inbox->id}/addresses", [
                'email' => '  Support@Company.com  ',
                'name' => 'Company Support',
            ])->assertOk()->assertJsonPath('address.email', 'support@company.com');

        $this->assertDatabaseHas('help_center_email_addresses', ['email' => 'support@company.com']);
    }

    public function test_an_address_already_on_another_inbox_is_rejected(): void
    {
        // Criterion 18, with §7's exact wording.
        $this->setUpHelpCenter();
        $space = $this->space();
        $first = $this->inbox($space);
        $second = $this->inbox($space, ['name' => 'Enterprise Support', 'inbound_id' => 'b7c3n1p5', 'position' => 2]);

        $first->emailAddresses()->create(['tenant_id' => $this->workspace->id, 'email' => 'support@company.com']);

        $this->actingAs($this->owner)
            ->postJson("/help-center/inboxes/{$second->id}/addresses", ['email' => 'SUPPORT@company.com'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email')
            ->assertJsonPath('errors.email.0', 'This email address is already connected to another Inbox.');
    }

    public function test_the_same_address_is_free_in_another_workspace(): void
    {
        // Criterion 19 — uniqueness is per workspace (HC-D6), because two companies may each
        // publish their own support@ address.
        $this->setUpHelpCenter();
        $inbox = $this->inbox();
        $inbox->emailAddresses()->create(['tenant_id' => $this->workspace->id, 'email' => 'support@company.com']);

        $other = $this->otherWorkspace();
        tenancy()->initialize($other);

        $theirSpace = HelpCenterSpace::create([
            'tenant_id' => $other->id, 'name' => 'Support', 'types' => ['Other'],
            'lead_user_id' => $other->creator->id, 'created_by' => $other->creator->id,
        ]);
        $theirInbox = HelpCenterInbox::create([
            'tenant_id' => $other->id, 'help_center_space_id' => $theirSpace->id,
            'name' => 'General', 'inbound_id' => 'z9y8x7w6', 'setup_completed_at' => now(),
        ]);
        $theirs = $theirInbox->emailAddresses()->create([
            'tenant_id' => $other->id, 'email' => 'support@company.com',
        ]);

        tenancy()->initialize($this->workspace);

        $this->assertNotNull($theirs->id);
        $this->assertSame(2, HelpCenterEmailAddress::query()->withoutGlobalScopes()
            ->where('email', 'support@company.com')->count());
    }

    public function test_a_malformed_address_is_rejected(): void
    {
        // Criterion 20.
        $this->setUpHelpCenter();
        $inbox = $this->inbox();

        foreach (['', 'not-an-email', 'missing@domain', 'two@@at.com'] as $email) {
            $this->actingAs($this->owner)
                ->postJson("/help-center/inboxes/{$inbox->id}/addresses", ['email' => $email])
                ->assertStatus(422)->assertJsonValidationErrors('email');
        }
    }

    public function test_many_addresses_route_into_one_inbox(): void
    {
        // Criterion 21 / §20 rule 3.
        $this->setUpHelpCenter();
        $inbox = $this->inbox();

        foreach (['support@company.com', 'hello@company.com', 'help@company.com'] as $email) {
            $this->actingAs($this->owner)
                ->postJson("/help-center/inboxes/{$inbox->id}/addresses", ['email' => $email])
                ->assertOk()
                // They start "Setup Required": nothing has proved the forwarding works (§6).
                ->assertJsonPath('address.status_label', 'Setup Required');
        }

        $this->assertSame(3, $inbox->emailAddresses()->count());
    }

    public function test_an_address_can_be_removed(): void
    {
        // Criterion 22.
        $this->setUpHelpCenter();
        $inbox = $this->inbox();
        $address = $inbox->emailAddresses()->create([
            'tenant_id' => $this->workspace->id, 'email' => 'support@company.com',
        ]);

        $this->actingAs($this->owner)
            ->deleteJson("/help-center/inboxes/{$inbox->id}/addresses/{$address->id}")
            ->assertOk();

        $this->assertDatabaseMissing('help_center_email_addresses', ['id' => $address->id]);
    }

    public function test_an_address_cannot_be_removed_through_a_different_inbox(): void
    {
        $this->setUpHelpCenter();
        $space = $this->space();
        $first = $this->inbox($space);
        $second = $this->inbox($space, ['name' => 'Enterprise', 'inbound_id' => 'b7c3n1p5', 'position' => 2]);

        $address = $first->emailAddresses()->create([
            'tenant_id' => $this->workspace->id, 'email' => 'support@company.com',
        ]);

        // Otherwise a Space Lead who may manage the second Inbox could reach into the first.
        $this->actingAs($this->owner)
            ->deleteJson("/help-center/inboxes/{$second->id}/addresses/{$address->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('help_center_email_addresses', ['id' => $address->id]);
    }

    public function test_the_model_normalizes_on_every_write_path(): void
    {
        // Not only through the form request: the ingestion of the next phase will match on this
        // column, and would otherwise miss `Support@Company.com`.
        $this->setUpHelpCenter();
        $inbox = $this->inbox();

        $address = $inbox->emailAddresses()->create([
            'tenant_id' => $this->workspace->id, 'email' => '  BILLING@Company.COM ',
        ]);

        $this->assertSame('billing@company.com', $address->fresh()->email);
    }
}
