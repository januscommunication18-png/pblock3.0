<?php

namespace Tests\Feature\Workspace;

use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SwitchWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_can_switch_active_workspace(): void
    {
        $user = User::factory()->create();
        $creator = app(WorkspaceCreator::class);
        $a = $creator->create($user, ['name' => 'Alpha', 'slug' => 'alpha', 'company_size' => '2-10']);
        $b = $creator->create($user, ['name' => 'Beta', 'slug' => 'beta', 'company_size' => '2-10']);

        // create() leaves Beta active (last created); switch back to Alpha.
        $this->assertSame($b->id, $user->fresh()->current_workspace_id);

        $this->actingAs($user->fresh())
            ->post(route('workspaces.switch', $a->id))
            ->assertRedirect(route('welcome'));

        $this->assertSame($a->id, $user->fresh()->current_workspace_id);
    }

    public function test_cannot_switch_into_a_workspace_you_are_not_a_member_of(): void
    {
        $owner = User::factory()->create();
        $foreign = app(WorkspaceCreator::class)->create($owner, [
            'name' => 'Foreign', 'slug' => 'foreign', 'company_size' => '2-10',
        ]);

        $intruder = User::factory()->create();
        $own = app(WorkspaceCreator::class)->create($intruder, [
            'name' => 'Mine', 'slug' => 'mine', 'company_size' => '2-10',
        ]);

        $this->actingAs($intruder->fresh())
            ->post(route('workspaces.switch', $foreign->id))
            ->assertForbidden();

        // Active workspace unchanged.
        $this->assertSame($own->id, $intruder->fresh()->current_workspace_id);
    }
}
