<?php

namespace Tests\Feature\Workspace;

use App\Models\OnboardingProfile;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CreateWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    /** A fully-onboarded user who already owns a workspace. */
    private function activeUser(): User
    {
        $user = User::factory()->create(['full_name' => 'Rohit', 'email' => 'rohit@example.com']);
        OnboardingProfile::create([
            'user_id' => $user->id,
            'current_step' => OnboardingProfile::STEP_COMPLETED,
            'completed_at' => now(),
            'workspace_setup_completed_at' => now(),
        ]);
        app(WorkspaceCreator::class)->create($user, [
            'name' => 'First WS', 'slug' => 'first-ws', 'company_size' => '2-10',
        ]);

        return $user->fresh();
    }

    public function test_additional_workspace_screen_renders_with_classic_coming_soon(): void
    {
        $this->actingAs($this->activeUser())
            ->get(route('workspaces.create'))
            ->assertOk()
            ->assertSee('Choose your view')
            ->assertSee('Coming soon', false);
    }

    /**
     * "Choose your view" is announced and operated as a radio group.
     *
     * It used to be a row of plain buttons whose selected state lived only in classes the
     * script rewrote — nothing named the group, nothing said which option was chosen, and the
     * arrow keys did nothing. The state now lives in `aria-checked`, which is also what the
     * stylesheet paints from, so this guards the visual and the announced state at once.
     */
    public function test_the_view_chooser_is_a_radio_group(): void
    {
        $html = $this->actingAs($this->activeUser())
            ->get(route('workspaces.create'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('role="radiogroup"', $html);
        $this->assertStringContainsString('aria-labelledby="view-label"', $html);
        $this->assertStringContainsString('role="radio" aria-checked="false"', $html);

        // Classic is Coming Soon: it must not be offered as a selectable option (WS-VIEW-002).
        $this->assertSame(1, substr_count($html, 'role="radio"'));

        // The tick sits on a brand-blue circle and is a currentColor glyph under the Font
        // Awesome icon set, so it has to be told to be white or it renders dark on blue.
        $this->assertStringContainsString('bg-brand text-white', $html);
    }

    public function test_additional_agile_workspace_can_be_created(): void
    {
        $user = $this->activeUser();

        $this->actingAs($user)->post(route('workspaces.store'), [
            'name' => 'Second WS', 'slug' => 'second-ws', 'team_size' => '11-50', 'view_type' => 'agile',
        ])->assertRedirect(route('welcome'));

        $this->assertDatabaseHas('tenants', ['slug' => 'second-ws', 'view_type' => 'agile']);
        $this->assertSame('second-ws', $user->fresh()->currentWorkspace->slug);
    }

    public function test_classic_cannot_be_created_via_the_form(): void
    {
        $this->actingAs($this->activeUser())->post(route('workspaces.store'), [
            'name' => 'Classic WS', 'slug' => 'classic-ws', 'team_size' => '11-50', 'view_type' => 'classic',
        ])->assertSessionHasErrors('view_type'); // WS-VIEW-002/003

        $this->assertDatabaseMissing('tenants', ['slug' => 'classic-ws']);
    }

    public function test_view_is_required_for_additional_creation(): void
    {
        $this->actingAs($this->activeUser())->post(route('workspaces.store'), [
            'name' => 'No View', 'slug' => 'no-view', 'team_size' => '11-50',
        ])->assertSessionHasErrors('view_type');
    }

    public function test_service_rejects_classic_view_even_if_request_is_bypassed(): void
    {
        // WS-VIEW-003: client tampering cannot create Classic before release.
        $user = $this->activeUser();

        $this->expectException(ValidationException::class);
        app(WorkspaceCreator::class)->create($user, [
            'name' => 'Sneaky', 'slug' => 'sneaky', 'company_size' => '2-10', 'view_type' => 'classic',
        ]);

        $this->assertDatabaseMissing('tenants', ['slug' => 'sneaky']);
    }

    public function test_slug_availability_endpoint(): void
    {
        Workspace::factory()->create(['slug' => 'busy-slug']);
        $user = $this->activeUser();

        $this->actingAs($user)->getJson(route('workspaces.slug', ['slug' => 'busy-slug']))
            ->assertJson(['available' => false]);

        $this->actingAs($user)->getJson(route('workspaces.slug', ['slug' => 'totally-free-slug']))
            ->assertJson(['available' => true]);

        $this->actingAs($user)->getJson(route('workspaces.slug', ['slug' => 'admin']))
            ->assertJson(['available' => false]); // reserved
    }
}
