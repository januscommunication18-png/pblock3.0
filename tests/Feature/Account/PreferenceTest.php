<?php

namespace Tests\Feature\Account;

use App\Http\Controllers\Account\PreferenceController;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Project\ProjectTestCase;

/**
 * The account modal's Preference tab — Language & Time (Account §2).
 *
 * Workspace settings behind a personal-looking menu, so most of what matters here is who may
 * reach them and what a stored value is allowed to be.
 */
class PreferenceTest extends ProjectTestCase
{
    use RefreshDatabase;

    public function test_an_owner_saves_language_and_time(): void
    {
        [$owner, $ws] = $this->owner();

        $this->actingAs($owner)->patchJson(route('account.preference.update'), [
            'timezone' => 'Asia/Kolkata',
            'language' => 'en',
            'first_day_of_week' => 1,
            // Deliberately out of order and with a duplicate: the client should not have to be
            // careful about either.
            'weekend_days' => [7, 6, 6],
        ])->assertOk();

        /** @var Workspace $fresh */
        $fresh = Workspace::find($ws->id);

        $this->assertSame('Asia/Kolkata', $fresh->timezone);
        $this->assertSame('en', $fresh->language);
        $this->assertSame(1, $fresh->first_day_of_week);
        $this->assertSame([6, 7], $fresh->weekend_days, 'stored in week order, without duplicates');

        // Real columns, not stancl's `data` overflow — the trap getCustomColumns() exists for.
        $row = DB::table('tenants')->where('id', $ws->id)->first();
        $this->assertSame('en', $row->language);
        $this->assertSame(1, (int) $row->first_day_of_week);
    }

    /** A weekend can be empty — some workspaces work every day. */
    public function test_the_weekend_may_be_empty(): void
    {
        [$owner, $ws] = $this->owner();

        $this->actingAs($owner)->patchJson(route('account.preference.update'), [
            'timezone' => 'UTC', 'language' => 'en', 'first_day_of_week' => 1, 'weekend_days' => [],
        ])->assertOk();

        $this->assertSame([], Workspace::find($ws->id)->weekend_days);
    }

    /**
     * These are workspace settings, so a member cannot write them.
     *
     * 403 rather than 422: the payload is fine, the caller is not, and answering with a
     * validation error would tell them to fix the wrong thing.
     */
    public function test_a_member_cannot_change_the_workspace_preferences(): void
    {
        [$owner, $ws] = $this->owner();
        $member = $this->member($ws, 'member', 'member@example.com');

        $this->actingAs($member)->patchJson(route('account.preference.update'), [
            'timezone' => 'Asia/Kolkata', 'language' => 'en', 'first_day_of_week' => 1, 'weekend_days' => [6, 7],
        ])->assertForbidden();

        $this->assertNotSame('Asia/Kolkata', Workspace::find($ws->id)->timezone);
    }

    /** …and does not even see the tab's contents, which is why the panel is absent for them. */
    public function test_the_tab_is_rendered_only_for_owners_and_admins(): void
    {
        [$owner, $ws] = $this->owner();
        $member = $this->member($ws, 'member', 'member@example.com');

        $ownerHtml = $this->actingAs($owner)->get(route('projects.index'))->assertOk()->getContent();
        $this->assertStringContainsString('id="account-preference-root"', $ownerHtml);

        $memberHtml = $this->actingAs($member)->get(route('projects.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('id="account-preference-root"', $memberHtml);
        // …and the several-hundred-entry timezone list is not rendered into their page either.
        $this->assertStringNotContainsString('account/preference.js', $memberHtml);
    }

    /**
     * A language without a pack can be offered but not chosen.
     *
     * The picker shows what is coming — that is more useful than a list of one — so the
     * refusal has to live on the server, or the interface ends up in a language that is not
     * there.
     */
    public function test_only_an_available_language_can_be_stored(): void
    {
        [$owner, $ws] = $this->owner();

        $this->actingAs($owner)->patchJson(route('account.preference.update'), [
            'timezone' => 'UTC', 'language' => 'fr', 'first_day_of_week' => 1, 'weekend_days' => [6, 7],
        ])->assertStatus(422);

        $this->assertNotSame('fr', Workspace::find($ws->id)->language);
    }

    public function test_a_workspace_that_never_opened_the_tab_has_sensible_defaults(): void
    {
        [, $ws] = $this->owner();

        $payload = PreferenceController::payload(Workspace::find($ws->id));

        $this->assertSame(config('workspace.week_defaults.first_day'), $payload['first_day_of_week']);
        $this->assertSame(config('workspace.week_defaults.weekend'), $payload['weekend_days']);
        $this->assertNotEmpty($payload['timezones'], 'the picker needs its option list');
    }
}
