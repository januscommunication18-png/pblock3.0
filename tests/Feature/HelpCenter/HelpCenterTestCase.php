<?php

namespace Tests\Feature\HelpCenter;

use App\Models\HelpCenterInbox;
use App\Models\HelpCenterSpace;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\WorkspaceCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Shared setup for the Help Center suite (docs/features/help-center.md).
 *
 * Every test here needs the same three things — a workspace with the app switched ON, somebody
 * who may configure it, and usually somebody who may not. Building them through
 * WorkspaceCreator rather than by hand means these tests exercise the same enablement path the
 * application uses, so a change to how an app is switched on is caught here rather than in
 * production.
 */
abstract class HelpCenterTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Workspace $workspace;

    /** A workspace with the Help Center enabled, and its owner signed up. */
    protected function setUpHelpCenter(bool $enabled = true): void
    {
        $this->owner = User::factory()->create(['full_name' => 'Rohit Philip', 'email' => 'owner@example.com']);

        $this->workspace = app(WorkspaceCreator::class)->create($this->owner, [
            'name' => 'Acme Inc',
            'slug' => 'acme-inc',
            'company_size' => '2-10',
            'apps' => $enabled ? ['projects', 'helpdesk'] : ['projects'],
        ]);

        $this->owner = $this->owner->fresh();
        tenancy()->initialize($this->workspace);
    }

    /** Another active member of the workspace — an "Agent" in §19's terms. */
    protected function member(string $email = 'sarah@example.com', string $role = 'member'): User
    {
        $user = User::factory()->create(['full_name' => 'Sarah Johnson', 'email' => $email]);

        WorkspaceMembership::create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $user->id,
            'role' => $role,
            'status' => WorkspaceMembership::STATUS_ACTIVE,
            'joined_at' => now(),
        ]);

        $user->forceFill(['current_workspace_id' => $this->workspace->id])->save();

        return $user->fresh();
    }

    /** A Space, created directly — the wizard's own path is tested separately. */
    protected function space(array $attributes = []): HelpCenterSpace
    {
        return HelpCenterSpace::create(array_merge([
            'tenant_id' => $this->workspace->id,
            'name' => 'Customer Support',
            'description' => 'Handles customer product questions.',
            'types' => ['Customer Support'],
            'lead_user_id' => $this->owner->id,
            'created_by' => $this->owner->id,
            'position' => 1,
        ], $attributes));
    }

    /**
     * An Inbox whose setup is finished — which is what makes the workspace's onboarding
     * complete (HC-D3), and therefore what most of these tests need in order to get past the
     * wizard at all.
     */
    protected function inbox(?HelpCenterSpace $space = null, array $attributes = []): HelpCenterInbox
    {
        $space ??= $this->space();

        return HelpCenterInbox::create(array_merge([
            'tenant_id' => $this->workspace->id,
            'help_center_space_id' => $space->id,
            'name' => 'General Support',
            'inbound_id' => 'a8f4k2m9',
            'setup_completed_at' => now(),
            'created_by' => $this->owner->id,
            'position' => 1,
        ], $attributes));
    }

    /** A second workspace, for the isolation tests. */
    protected function otherWorkspace(): Workspace
    {
        $other = User::factory()->create(['full_name' => 'Other Owner', 'email' => 'other@example.com']);

        $workspace = app(WorkspaceCreator::class)->create($other, [
            'name' => 'Globex',
            'slug' => 'globex',
            'company_size' => '2-10',
            'apps' => ['projects', 'helpdesk'],
        ]);

        // Back to the workspace under test: creating another one leaves tenancy pointing at it.
        tenancy()->initialize($this->workspace);

        return $workspace;
    }
}
