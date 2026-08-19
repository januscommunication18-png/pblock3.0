<?php

namespace Tests\Feature\HelpDesk;

use App\Models\HelpDeskActivity;
use App\Models\HelpDeskEmailAddress;
use App\Models\HelpDeskInbox;
use App\Models\HelpDeskMember;
use App\Models\HelpDeskSpace;
use App\Models\User;
use App\Models\Workspace;
use App\Services\HelpDesk\HelpDeskAccess;
use App\Services\HelpDesk\HelpDeskSpaceContext;
use App\Services\HelpDesk\InboundEmailIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Spaces and inbox assignment (Workspace & Inbox Assignment requirements).
 *
 * §20's acceptance criteria, one test each — including the two that are really claims about what
 * a move does NOT do: a conversation and an email address stay attached to the inbox when it
 * changes hands, and a space in context can never widen what somebody is allowed to see.
 */
class SpaceTest extends HelpDeskTestCase
{
    use RefreshDatabase;

    /** Create a space the way the creation page does. */
    private function makeSpace(Workspace $workspace, User $actor, string $name, array $inboxIds = []): HelpDeskSpace
    {
        $this->actingAs($actor->fresh())
            ->postJson(route('help-desk.spaces.store'), ['name' => $name, 'inbox_ids' => $inboxIds])
            ->assertOk();

        return $workspace->run(fn () => HelpDeskSpace::query()->where('name', $name)->firstOrFail());
    }

    /** An inbox created through the settings screen, optionally straight into a space (§11). */
    private function makeInbox(Workspace $workspace, User $actor, string $name, ?int $spaceId = null): HelpDeskInbox
    {
        $this->actingAs($actor->fresh())
            ->postJson(route('help-desk.inboxes.store'), ['name' => $name, 'help_desk_space_id' => $spaceId])
            ->assertOk();

        return $workspace->run(fn () => HelpDeskInbox::query()->where('name', $name)->firstOrFail());
    }

    // ---- the section exists, and a Help Desk starts with one space -----------------------------

    public function test_a_provisioned_help_desk_starts_with_one_space_holding_its_inbox(): void
    {
        [$owner, $workspace] = $this->owner();
        $inbox = $this->firstInbox($workspace);

        $space = $workspace->run(fn () => HelpDeskSpace::query()->firstOrFail());

        // The hierarchy is Help Desk → space → inbox, so a fresh Help Desk has one of each
        // rather than an inbox belonging to nothing.
        $this->assertSame(config('help-desk.default_space'), $space->name);
        $this->assertSame($space->id, $inbox->help_desk_space_id);
    }

    public function test_the_spaces_screen_lists_what_each_space_holds(): void
    {
        [$owner, $workspace] = $this->owner();
        $this->helpDesk($workspace, $owner);

        $bootstrap = $this->actingAs($owner->fresh())
            ->get(route('help-desk.spaces'))
            ->assertOk()->viewData('bootstrap');

        $row = $bootstrap['spaces'][0];

        $this->assertSame(config('help-desk.default_space'), $row['name']);
        $this->assertSame(1, $row['inboxes_count']);
        $this->assertSame(0, $row['open_conversations']);
    }

    public function test_the_space_page_shows_its_inboxes_and_puts_it_in_context(): void
    {
        [$owner, $workspace] = $this->owner();
        $this->helpDesk($workspace, $owner);
        $inbox = $this->firstInbox($workspace);
        $space = $workspace->run(fn () => HelpDeskSpace::query()->firstOrFail());

        $bootstrap = $this->actingAs($owner->fresh())
            ->get(route('help-desk.spaces.show', $space))
            ->assertOk()->viewData('bootstrap');

        $this->assertSame($space->name, $bootstrap['space']['name']);
        $this->assertSame([$inbox->name], array_column($bootstrap['inboxes'], 'name'));

        // §9: opening a space is standing in it — the switcher follows you here rather than
        // staying wherever it was last left.
        $this->assertSame(
            $space->id,
            app(HelpDeskSpaceContext::class)->current($owner->fresh(), $workspace),
        );
    }

    public function test_several_spaces_can_be_created(): void
    {
        [$owner, $workspace] = $this->owner();
        $this->helpDesk($workspace, $owner);

        $this->makeSpace($workspace, $owner, 'Partner Support');
        $this->makeSpace($workspace, $owner, 'Retail Support');

        // §17's three support teams, and §18's two businesses, are the same feature.
        $this->assertSame(3, $workspace->run(fn () => HelpDeskSpace::query()->count()));
    }

    /**
     * Type is a list the reader writes, not one value picked from ours (H45).
     *
     * Several values, any text at all, and the six the form suggests are suggestions rather
     * than a gate — "Enterprise Onboarding" is a perfectly good answer that no dropdown we
     * write would ever have contained.
     */
    public function test_a_space_carries_several_free_text_types(): void
    {
        [$owner, $workspace] = $this->owner();
        $this->helpDesk($workspace, $owner);

        $this->actingAs($owner->fresh())->postJson(route('help-desk.spaces.store'), [
            'name' => 'Partner Support',
            'types' => ['Partner Support', 'Business Unit', 'Enterprise Onboarding'],
        ])->assertOk();

        $space = $workspace->run(fn () => HelpDeskSpace::query()->where('name', 'Partner Support')->firstOrFail());

        $this->assertSame(
            ['Partner Support', 'Business Unit', 'Enterprise Onboarding'],
            $space->typeLabels(),
        );
    }

    public function test_types_are_tidied_rather_than_stored_as_typed(): void
    {
        [$owner, $workspace] = $this->owner();
        $this->helpDesk($workspace, $owner);

        $this->actingAs($owner->fresh())->postJson(route('help-desk.spaces.store'), [
            'name' => 'Retail Support',
            // What a chip field actually collects: padding, a blank from a stray comma, and
            // the same value twice in two spellings.
            'types' => ['  Retail   Support  ', '', 'retail support', 'Business Unit'],
        ])->assertOk();

        $space = $workspace->run(fn () => HelpDeskSpace::query()->where('name', 'Retail Support')->firstOrFail());

        // Trimmed, de-duplicated case-insensitively, and the FIRST spelling kept — it is the
        // one the person typed.
        $this->assertSame(['Retail Support', 'Business Unit'], $space->typeLabels());
    }

    public function test_types_can_be_replaced_and_cleared_from_the_edit_dialog(): void
    {
        [$owner, $workspace] = $this->owner();
        $this->helpDesk($workspace, $owner);
        $space = $this->makeSpace($workspace, $owner, 'Partner Support');

        $this->actingAs($owner->fresh())->patchJson(route('help-desk.spaces.update', $space), [
            'name' => 'Partner Support', 'types' => ['Reseller'],
        ])->assertOk()->assertJsonPath('space.types', ['Reseller']);

        // And emptying the field means none — not "unchanged".
        $this->actingAs($owner->fresh())->patchJson(route('help-desk.spaces.update', $space), [
            'name' => 'Partner Support', 'types' => [],
        ])->assertOk()->assertJsonPath('space.types', []);

        $this->assertNull($workspace->run(fn () => HelpDeskSpace::find($space->id))->types);
    }

    public function test_too_many_types_are_refused(): void
    {
        [$owner, $workspace] = $this->owner();
        $this->helpDesk($workspace, $owner);

        $this->actingAs($owner->fresh())->postJson(route('help-desk.spaces.store'), [
            'name' => 'Everything',
            'types' => array_map(fn (int $i) => "Type {$i}", range(1, HelpDeskSpace::MAX_TYPES + 3)),
        ])->assertStatus(422)->assertJsonValidationErrors('types');
    }

    public function test_two_spaces_cannot_share_a_name(): void
    {
        [$owner, $workspace] = $this->owner();
        $this->helpDesk($workspace, $owner);
        $this->makeSpace($workspace, $owner, 'Partner Support');

        $this->actingAs($owner->fresh())
            ->postJson(route('help-desk.spaces.store'), ['name' => 'Partner Support'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    // ---- assignment (§6, §7, §11, §12) ---------------------------------------------------------

    public function test_a_space_can_hold_several_inboxes(): void
    {
        [$owner, $workspace] = $this->owner();
        $this->helpDesk($workspace, $owner);

        $space = $this->makeSpace($workspace, $owner, 'Retail Support');
        $this->makeInbox($workspace, $owner, 'Retail', $space->id);
        $this->makeInbox($workspace, $owner, 'Retail Returns', $space->id);

        $this->assertSame(2, $workspace->run(fn () => HelpDeskInbox::query()
            ->where('help_desk_space_id', $space->id)->count()));
    }

    public function test_an_inbox_created_from_a_space_is_assigned_to_it(): void
    {
        [$owner, $workspace] = $this->owner();
        $this->helpDesk($workspace, $owner);
        $space = $this->makeSpace($workspace, $owner, 'Partner Support');

        // §11's flow: the space screen links to the inbox screen with `?space=`, which is what
        // preselects it — so an inbox created there arrives already assigned.
        $bootstrap = $this->actingAs($owner->fresh())
            ->get(route('help-desk.inboxes', ['space' => $space->id]))
            ->assertOk()->viewData('bootstrap');

        $this->assertSame($space->id, $bootstrap['new_in_space']);

        $inbox = $this->makeInbox($workspace, $owner, 'Partners', $space->id);
        $this->assertSame($space->id, $inbox->help_desk_space_id);
    }

    public function test_an_unassigned_inbox_can_be_assigned_to_a_space(): void
    {
        [$owner, $workspace] = $this->owner();
        $this->helpDesk($workspace, $owner);

        $orphan = $this->makeInbox($workspace, $owner, 'Billing');
        $workspace->run(fn () => $orphan->forceFill(['help_desk_space_id' => null])->save());

        $space = $this->makeSpace($workspace, $owner, 'Partner Support');

        // Surfaced on the list screen rather than left to be discovered: an inbox in no space
        // still receives mail.
        $unassigned = $this->actingAs($owner->fresh())->get(route('help-desk.spaces'))
            ->assertOk()->viewData('bootstrap')['unassigned'];
        $this->assertSame(['Billing'], array_column($unassigned, 'name'));

        $this->actingAs($owner->fresh())
            ->postJson(route('help-desk.spaces.assign', $space), ['inbox_ids' => [$orphan->id]])
            ->assertOk()
            ->assertJsonPath('moved', 1);

        $this->assertSame($space->id, $workspace->run(fn () => HelpDeskInbox::find($orphan->id))->help_desk_space_id);
    }

    public function test_an_inbox_belongs_to_one_space_so_assigning_it_again_moves_it(): void
    {
        [$owner, $workspace] = $this->owner();
        $this->helpDesk($workspace, $owner);

        $first = $this->makeSpace($workspace, $owner, 'Partner Support');
        $inbox = $this->makeInbox($workspace, $owner, 'Partners', $first->id);
        $second = $this->makeSpace($workspace, $owner, 'Partner Operations');

        $this->actingAs($owner->fresh())
            ->postJson(route('help-desk.spaces.assign', $second), ['inbox_ids' => [$inbox->id]])
            ->assertOk();

        // §7's rule: one inbox, one space. Assigning is therefore always a move.
        $this->assertSame($second->id, $workspace->run(fn () => HelpDeskInbox::find($inbox->id))->help_desk_space_id);
        $this->assertSame(0, $workspace->run(fn () => HelpDeskInbox::query()
            ->where('help_desk_space_id', $first->id)->count()));

        // Recorded with both ends named, resolved at write time.
        $entry = $workspace->run(fn () => HelpDeskActivity::query()
            ->where('event', HelpDeskActivity::EVENT_INBOX_ASSIGNED)->latest('id')->firstOrFail());
        $this->assertSame('Partner Support', $entry->meta['from']);
        $this->assertSame('Partner Operations', $entry->meta['to']);
    }

    public function test_a_move_keeps_the_inboxs_conversations_and_email_configuration(): void
    {
        [$owner, $workspace] = $this->owner();
        $this->helpDesk($workspace, $owner);
        $inbox = $this->firstInbox($workspace);

        $this->actingAs($owner->fresh())
            ->postJson(route('help-desk.inboxes.addresses.store', $inbox), ['address' => 'support@acme.com'])
            ->assertOk();

        app(InboundEmailIngestor::class)->ingest([
            'delivered_to' => [$inbox->inbound_address],
            'to' => ['support@acme.com'],
            'from' => ['email' => 'dana@example.com', 'name' => 'Dana'],
            'subject' => 'Before the move',
            'message_id' => '<before@mail.example>',
        ]);

        $target = $this->makeSpace($workspace, $owner, 'Partner Support');

        $this->actingAs($owner->fresh())
            ->postJson(route('help-desk.spaces.assign', $target), ['inbox_ids' => [$inbox->id]])
            ->assertOk();

        $fresh = $workspace->run(fn () => HelpDeskInbox::find($inbox->id));

        /*
         * §7's promise in as many words: "existing conversations, contacts, routing rules and
         * email configuration will remain attached to the Inbox". True by construction — a move
         * writes one column on the inbox and touches nothing that hangs off it.
         */
        $this->assertSame($target->id, $fresh->help_desk_space_id);
        $this->assertSame(1, $workspace->run(fn () => $fresh->conversations()->count()));
        $this->assertSame('support@acme.com', $workspace->run(fn () => HelpDeskEmailAddress::query()->value('address')));
        $this->assertSame($inbox->inbound_address, $fresh->inbound_address);
    }

    public function test_an_inbox_can_be_taken_out_of_a_space(): void
    {
        [$owner, $workspace] = $this->owner();
        $this->helpDesk($workspace, $owner);
        $inbox = $this->firstInbox($workspace);
        $space = $workspace->run(fn () => HelpDeskSpace::query()->firstOrFail());

        $this->actingAs($owner->fresh())
            ->deleteJson(route('help-desk.spaces.unassign', ['space' => $space->id, 'inbox' => $inbox->id]))
            ->assertOk();

        $this->assertNull($workspace->run(fn () => HelpDeskInbox::find($inbox->id))->help_desk_space_id);
    }

    // ---- archiving (§8) -------------------------------------------------------------------------

    public function test_a_space_holding_inboxes_cannot_be_archived(): void
    {
        [$owner, $workspace] = $this->owner();
        $this->helpDesk($workspace, $owner);
        $space = $workspace->run(fn () => HelpDeskSpace::query()->firstOrFail());

        // Refused rather than orphaning what is inside it — the same position H21 takes on
        // deleting an inbox.
        $this->actingAs($owner->fresh())
            ->postJson(route('help-desk.spaces.archive', $space))
            ->assertStatus(422);

        $this->assertFalse($workspace->run(fn () => HelpDeskSpace::find($space->id))->isArchived());
    }

    public function test_an_empty_space_can_be_archived_and_restored(): void
    {
        [$owner, $workspace] = $this->owner();
        $this->helpDesk($workspace, $owner);
        $space = $this->makeSpace($workspace, $owner, 'Retired Brand');

        $this->actingAs($owner->fresh())->postJson(route('help-desk.spaces.archive', $space))->assertOk();
        $this->assertTrue($workspace->run(fn () => HelpDeskSpace::find($space->id))->isArchived());

        // Out of the list, and out of the switcher, until it is asked for.
        $listed = $this->actingAs($owner->fresh())->get(route('help-desk.spaces'))
            ->assertOk()->viewData('bootstrap')['spaces'];
        $this->assertNotContains('Retired Brand', array_column($listed, 'name'));

        $this->actingAs($owner->fresh())->postJson(route('help-desk.spaces.restore', $space))->assertOk();
        $this->assertFalse($workspace->run(fn () => HelpDeskSpace::find($space->id))->isArchived());
    }

    // ---- switching and scoping (§13, §14) ---------------------------------------------------------

    public function test_switching_space_scopes_the_conversation_list(): void
    {
        [$owner, $workspace] = $this->owner();
        $deskAdmin = $this->member($workspace, 'member', 'desk-admin@example.com');
        $this->addToHelpDesk($workspace, $owner, $deskAdmin, HelpDeskMember::ROLE_ADMIN);

        $support = $this->firstInbox($workspace);
        $partnerSpace = $this->makeSpace($workspace, $owner, 'Partner Support');
        $partners = $this->makeInbox($workspace, $owner, 'Partners', $partnerSpace->id);

        foreach ([[$support, 'support'], [$partners, 'partners']] as [$inbox, $tag]) {
            app(InboundEmailIngestor::class)->ingest([
                'to' => [$inbox->inbound_address],
                'from' => ['email' => "{$tag}@example.com"],
                'subject' => "A {$tag} question",
                'message_id' => "<{$tag}@mail.example>",
            ]);
        }

        $actor = $deskAdmin->fresh();

        // Across everything first — that is where somebody who has not chosen starts.
        $this->assertCount(2, $this->actingAs($actor)->get(route('help-desk.conversations'))
            ->assertOk()->viewData('bootstrap')['conversations']);

        $this->actingAs($actor)->post(route('help-desk.spaces.switch'), ['space' => $partnerSpace->id])
            ->assertRedirect();

        // §13: "selecting Partner Support should show only partner support conversations".
        $scoped = $this->actingAs($actor)->get(route('help-desk.conversations'))
            ->assertOk()->viewData('bootstrap');

        $this->assertCount(1, $scoped['conversations']);
        $this->assertSame('A partners question', $scoped['conversations'][0]['subject']);
        $this->assertSame(['Partners'], array_column($scoped['inboxes'], 'name'));
    }

    public function test_a_space_in_context_can_never_widen_what_somebody_may_see(): void
    {
        [$owner, $workspace] = $this->owner();
        $this->helpDesk($workspace, $owner);

        $space = $this->makeSpace($workspace, $owner, 'Retail Support');
        $mine = $this->makeInbox($workspace, $owner, 'Retail', $space->id);
        $theirs = $this->makeInbox($workspace, $owner, 'Retail Returns', $space->id);

        foreach ([[$mine, 'mine'], [$theirs, 'theirs']] as [$inbox, $tag]) {
            app(InboundEmailIngestor::class)->ingest([
                'to' => [$inbox->inbound_address],
                'from' => ['email' => "{$tag}@example.com"],
                'subject' => "A {$tag} question",
                'message_id' => "<{$tag}@mail.example>",
            ]);
        }

        $agent = $this->member($workspace, 'member', 'agent@example.com');
        $this->addToHelpDesk($workspace, $owner, $agent, HelpDeskMember::ROLE_AGENT, [$mine->id]);

        $actor = $agent->fresh();
        $this->actingAs($actor)->post(route('help-desk.spaces.switch'), ['space' => $space->id])->assertRedirect();

        $scoped = $this->actingAs($actor)->get(route('help-desk.conversations'))
            ->assertOk()->viewData('bootstrap');

        // Context NARROWS; authorization decides. Being in a space holding two inboxes does not
        // hand somebody the one they were never given.
        $this->assertCount(1, $scoped['conversations']);
        $this->assertSame('A mine question', $scoped['conversations'][0]['subject']);
    }

    public function test_people_only_see_the_spaces_they_can_reach(): void
    {
        [$owner, $workspace] = $this->owner();
        $this->helpDesk($workspace, $owner);

        $partner = $this->makeSpace($workspace, $owner, 'Partner Support');
        $partners = $this->makeInbox($workspace, $owner, 'Partners', $partner->id);
        $retail = $this->makeSpace($workspace, $owner, 'Retail Support');
        $this->makeInbox($workspace, $owner, 'Retail', $retail->id);

        $agent = $this->member($workspace, 'member', 'agent@example.com');
        $this->addToHelpDesk($workspace, $owner, $agent, HelpDeskMember::ROLE_AGENT, [$partners->id]);

        // §15's "users should only see Workspaces they have permission to access", derived from
        // the inbox access this Help Desk already grants (H38).
        $visible = app(HelpDeskAccess::class)
            ->visibleSpaces($agent->fresh(), $workspace);

        $this->assertSame(['Partner Support'], array_column($visible, 'name'));

        // And a space they cannot reach cannot be switched into by editing the request.
        $this->actingAs($agent->fresh())
            ->post(route('help-desk.spaces.switch'), ['space' => $retail->id])
            ->assertForbidden();
    }

    public function test_a_manager_sees_every_space_including_empty_ones(): void
    {
        [$owner, $workspace] = $this->owner();
        $manager = $this->member($workspace, 'member', 'manager@example.com');
        $this->addToHelpDesk($workspace, $owner, $manager, HelpDeskMember::ROLE_MANAGER);
        $this->makeSpace($workspace, $owner, 'Brand New');

        $visible = app(HelpDeskAccess::class)
            ->visibleSpaces($manager->fresh(), $workspace);

        // Admins and Managers reach every inbox by role, so they reach every space — including
        // the ones holding nothing, which they are the only people who can do anything about.
        $this->assertEqualsCanonicalizing(
            [config('help-desk.default_space'), 'Brand New'],
            array_column($visible, 'name'),
        );
    }

    // ---- authorization and isolation ---------------------------------------------------------------

    public function test_an_agent_cannot_create_edit_or_archive_a_space(): void
    {
        [$owner, $workspace] = $this->owner();
        $this->helpDesk($workspace, $owner);
        $space = $workspace->run(fn () => HelpDeskSpace::query()->firstOrFail());

        $agent = $this->member($workspace, 'member', 'agent@example.com');
        $this->addToHelpDesk($workspace, $owner, $agent, HelpDeskMember::ROLE_AGENT);
        $actor = $agent->fresh();

        $this->actingAs($actor)->get(route('help-desk.spaces.create'))->assertForbidden();
        $this->actingAs($actor)->postJson(route('help-desk.spaces.store'), ['name' => 'Mine'])->assertForbidden();
        $this->actingAs($actor)->patchJson(route('help-desk.spaces.update', $space), ['name' => 'Theirs'])->assertForbidden();
        $this->actingAs($actor)->postJson(route('help-desk.spaces.archive', $space))->assertForbidden();
        $this->actingAs($actor)->postJson(route('help-desk.spaces.assign', $space), ['inbox_ids' => [1]])->assertForbidden();
    }

    public function test_another_workspaces_space_is_not_addressable(): void
    {
        [$ownerA, $workspaceA] = $this->owner('acme-inc');
        [$ownerB, $workspaceB] = $this->owner('globex');
        $this->helpDesk($workspaceA, $ownerA);
        $this->helpDesk($workspaceB, $ownerB);

        $theirs = $workspaceB->run(fn () => HelpDeskSpace::query()->firstOrFail());

        $this->actingAs($ownerA->fresh())->get(route('help-desk.spaces.show', $theirs))->assertNotFound();
        $this->actingAs($ownerA->fresh())
            ->patchJson(route('help-desk.spaces.update', $theirs), ['name' => 'Taken over'])
            ->assertNotFound();
    }

    public function test_an_inbox_from_another_help_desk_cannot_be_assigned(): void
    {
        [$ownerA, $workspaceA] = $this->owner('acme-inc');
        [$ownerB, $workspaceB] = $this->owner('globex');
        $mine = $this->makeSpace($workspaceA, $ownerA, 'Partner Support');
        $theirInbox = $this->firstInbox($workspaceB);

        $this->actingAs($ownerA->fresh())
            ->postJson(route('help-desk.spaces.assign', $mine), ['inbox_ids' => [$theirInbox->id]])
            ->assertOk()
            ->assertJsonPath('moved', 0);

        // Ignored, not moved: an id from a multi-select is the easiest thing in a request to
        // change, and this is the one mistake it could make that would matter.
        $this->assertNotSame(
            $mine->id,
            $workspaceB->run(fn () => HelpDeskInbox::find($theirInbox->id))->help_desk_space_id,
        );
    }

    public function test_the_switcher_will_not_redirect_off_the_help_desk(): void
    {
        [$owner, $workspace] = $this->owner();
        $this->helpDesk($workspace, $owner);

        // The return path comes from the request; honouring whatever it says is how a switcher
        // becomes an open redirect.
        $this->actingAs($owner->fresh())
            ->post(route('help-desk.spaces.switch'), ['space' => '', 'return' => 'https://evil.example/steal'])
            ->assertRedirect(route('help-desk.index'));
    }
}
