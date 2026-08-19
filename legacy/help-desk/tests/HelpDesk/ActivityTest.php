<?php

namespace Tests\Feature\HelpDesk;

use App\Models\HelpDeskActivity;
use App\Models\HelpDeskInbox;
use App\Models\HelpDeskMember;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The Help Desk activity stream (docs/features/help-desk.md — Phase 1, slice 4: FR-1.9, §8).
 *
 * What matters about an audit trail is that it records what happened, in the words things had
 * at the time, and that it cannot be reached by people who should not read it.
 */
class ActivityTest extends HelpDeskTestCase
{
    use RefreshDatabase;

    public function test_every_membership_change_is_recorded(): void
    {
        [$owner, $workspace] = $this->owner();
        $agent = $this->member($workspace, 'member', 'agent@example.com');
        $support = $this->firstInbox($workspace);

        $membership = $this->addToHelpDesk($workspace, $owner, $agent, HelpDeskMember::ROLE_AGENT, [$support->id]);

        $this->actingAs($owner->fresh())
            ->patchJson(route('help-desk.members.update', $membership), ['role' => HelpDeskMember::ROLE_VIEWER])->assertOk();
        $this->actingAs($owner->fresh())
            ->patchJson(route('help-desk.members.update', $membership), ['status' => HelpDeskMember::STATUS_INACTIVE])->assertOk();
        $this->actingAs($owner->fresh())
            ->deleteJson(route('help-desk.members.destroy', $membership))->assertOk();

        $events = $workspace->run(fn () => HelpDeskActivity::query()->orderBy('id')->pluck('event')->all());

        $this->assertSame([
            HelpDeskActivity::EVENT_MEMBER_ADDED,
            HelpDeskActivity::EVENT_MEMBER_ROLE_CHANGED,
            HelpDeskActivity::EVENT_MEMBER_STATUS_CHANGED,
            HelpDeskActivity::EVENT_MEMBER_REMOVED,
        ], $events);
    }

    public function test_adding_somebody_writes_one_entry_not_two(): void
    {
        [$owner, $workspace] = $this->owner();
        $agent = $this->member($workspace, 'member', 'agent@example.com');
        $support = $this->firstInbox($workspace);

        // Naming their inboxes is part of adding them; two entries for one action is a stream
        // that has to be read twice to learn one thing.
        $this->addToHelpDesk($workspace, $owner, $agent, HelpDeskMember::ROLE_AGENT, [$support->id]);

        $this->assertSame(1, $workspace->run(fn () => HelpDeskActivity::query()->count()));
    }

    public function test_a_change_that_changes_nothing_is_not_recorded(): void
    {
        [$owner, $workspace] = $this->owner();
        $agent = $this->member($workspace, 'member', 'agent@example.com');
        $membership = $this->addToHelpDesk($workspace, $owner, $agent, HelpDeskMember::ROLE_AGENT);

        $this->actingAs($owner->fresh())
            ->patchJson(route('help-desk.members.update', $membership), ['role' => HelpDeskMember::ROLE_AGENT])->assertOk();

        // A stream that records non-changes buries the entries that mean something.
        $this->assertSame(1, $workspace->run(fn () => HelpDeskActivity::query()->count()));
    }

    public function test_inbox_changes_are_recorded(): void
    {
        [$owner, $workspace] = $this->owner();
        $this->helpDesk($workspace, $owner);

        $this->actingAs($owner)->postJson(route('help-desk.inboxes.store'), ['name' => 'Billing'])->assertOk();
        $billing = $workspace->run(fn () => HelpDeskInbox::query()->where('name', 'Billing')->firstOrFail());
        $this->actingAs($owner->fresh())
            ->patchJson(route('help-desk.inboxes.update', $billing), ['name' => 'Payments'])->assertOk();

        $entries = $workspace->run(fn () => HelpDeskActivity::query()->orderBy('id')->get());

        $this->assertSame(HelpDeskActivity::EVENT_INBOX_CREATED, $entries[0]->event);
        $this->assertSame('created the inbox Billing', $entries[0]->sentence());

        // The old name is stored, so the entry still reads correctly after the rename.
        $this->assertSame(HelpDeskActivity::EVENT_INBOX_RENAMED, $entries[1]->event);
        $this->assertSame('renamed the inbox Billing to Payments', $entries[1]->sentence());
    }

    public function test_entries_keep_the_names_things_had_at_the_time(): void
    {
        [$owner, $workspace] = $this->owner();
        $agent = $this->member($workspace, 'member', 'agent@example.com');
        $this->addToHelpDesk($workspace, $owner, $agent, HelpDeskMember::ROLE_AGENT);

        $agent->forceFill(['full_name' => 'Renamed Person'])->save();

        $entry = $workspace->run(fn () => HelpDeskActivity::query()->firstOrFail());

        // History that changes when somebody is renamed is not history.
        $this->assertSame('Agent', $entry->subject);
        $this->assertSame('added Agent as Agent', $entry->sentence());
    }

    public function test_the_screen_is_readable_by_an_administrator_and_refused_to_an_agent(): void
    {
        [$owner, $workspace] = $this->owner();
        $agent = $this->member($workspace, 'member', 'agent@example.com');
        $this->addToHelpDesk($workspace, $owner, $agent, HelpDeskMember::ROLE_AGENT);

        $this->actingAs($owner->fresh())->get(route('help-desk.activity'))
            ->assertOk()->assertSee('Activity');

        // Whether a colleague was deactivated is a personnel matter, not an operational one.
        $this->actingAs($agent->fresh())->get(route('help-desk.activity'))->assertForbidden();
    }

    public function test_the_stream_is_paginated_and_newest_first(): void
    {
        [$owner, $workspace] = $this->owner();
        $this->helpDesk($workspace, $owner);

        // Comfortably past one page (30).
        for ($i = 1; $i <= 32; $i++) {
            $this->actingAs($owner->fresh())
                ->postJson(route('help-desk.inboxes.store'), ['name' => "Inbox {$i}"])->assertOk();
        }

        $first = $this->actingAs($owner->fresh())->get(route('help-desk.activity'))->assertOk();
        $entries = $first->viewData('entries');

        $this->assertSame(30, $entries->count());
        $this->assertSame(32, $entries->total());
        $this->assertSame('created the inbox Inbox 32', $entries->first()['sentence']);
        $first->assertSee('Older');
    }

    public function test_one_workspace_cannot_read_anothers_stream(): void
    {
        [$ownerA, $workspaceA] = $this->owner('acme-inc');
        [$ownerB, $workspaceB] = $this->owner('globex');
        $this->helpDesk($workspaceA, $ownerA);
        $this->helpDesk($workspaceB, $ownerB);

        $this->actingAs($ownerB)->postJson(route('help-desk.inboxes.store'), ['name' => 'Globex Only'])->assertOk();

        $this->actingAs($ownerA->fresh())->get(route('help-desk.activity'))
            ->assertOk()->assertDontSee('Globex Only');
    }
}
