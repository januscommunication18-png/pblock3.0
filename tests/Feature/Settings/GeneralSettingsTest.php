<?php

namespace Tests\Feature\Settings;

use App\Models\Workspace;
use App\Services\WorkspaceCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;

/** Administration > General (spec §4 / SET-G-*). */
class GeneralSettingsTest extends SettingsTestCase
{
    use RefreshDatabase;

    public function test_update_persists_identity_fields(): void
    {
        [$owner, $workspace] = $this->owner();

        $this->actingAs($owner)->patchJson(route('settings.general.update'), [
            'name' => 'Acme Global', 'company_size' => '11-50',
            'slug' => 'acme-global', 'timezone' => 'America/New_York',
        ])->assertOk()->assertJsonPath('workspace.slug', 'acme-global');

        $this->assertDatabaseHas('tenants', [
            'id' => $workspace->id, 'name' => 'Acme Global',
            'slug' => 'acme-global', 'company_size' => '11-50', 'timezone' => 'America/New_York',
        ]);
    }

    public function test_slug_must_be_unique_and_not_reserved(): void
    {
        [$owner] = $this->owner();
        Workspace::factory()->create(['slug' => 'taken-slug']);

        $this->actingAs($owner)->patchJson(route('settings.general.update'), [
            'name' => 'Acme', 'company_size' => '2-10', 'slug' => 'taken-slug', 'timezone' => 'UTC',
        ])->assertStatus(422)->assertJsonValidationErrors('slug');

        $this->actingAs($owner)->patchJson(route('settings.general.update'), [
            'name' => 'Acme', 'company_size' => '2-10', 'slug' => 'settings', 'timezone' => 'UTC',
        ])->assertStatus(422)->assertJsonValidationErrors('slug');
    }

    public function test_owner_can_delete_workspace_but_admin_cannot(): void
    {
        [$owner, $workspace] = $this->owner();
        $admin = $this->member($workspace, 'admin', 'admin@example.com');

        // Admin may manage settings but not delete (owner-only, SET-G-008).
        $this->actingAs($admin)->delete(route('settings.general.destroy'))->assertForbidden();
        $this->assertDatabaseHas('tenants', ['id' => $workspace->id]);

        $this->actingAs($owner)->delete(route('settings.general.destroy'))->assertRedirect(route('welcome'));
        $this->assertDatabaseMissing('tenants', ['id' => $workspace->id]);
        $this->assertDatabaseMissing('workspace_memberships', ['workspace_id' => $workspace->id]);
    }

    public function test_deleting_repairs_members_active_workspace_pointer(): void
    {
        [$owner, $workspace] = $this->owner();
        // Owner also belongs to a second workspace (creating it makes it active — WS-009).
        $other = app(WorkspaceCreator::class)->create($owner->fresh(), [
            'name' => 'Second', 'slug' => 'second-ws', 'company_size' => '2-10',
        ]);
        // Make the first workspace the active one again before deleting it.
        $owner->fresh()->forceFill(['current_workspace_id' => $workspace->id])->save();

        $this->actingAs($owner->fresh())->delete(route('settings.general.destroy'))->assertRedirect();

        $this->assertSame($other->id, $owner->fresh()->current_workspace_id);
    }
}
