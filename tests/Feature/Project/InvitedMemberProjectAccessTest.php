<?php

namespace Tests\Feature\Project;

use App\Models\Project;
use App\Models\ProjectMember;
use App\Services\WorkspaceCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * An invited member's projects: what they are offered, and whether it opens
 * (docs/features/workspace-project-access.md).
 *
 * The bug this guards against: /welcome kept its OWN copy of "which projects can this person
 * see", and that copy still granted access by `visibility`. So a Member invited into somebody
 * else's workspace was shown every public project in the sidebar — and every one of them
 * answered 404 when clicked, because `ProjectPolicy::view()` had long since stopped accepting
 * visibility as access.
 *
 * The rule under test is therefore not "the sidebar lists the right things", it is **every
 * surface lists the same things, and every one of them opens**.
 */
class InvitedMemberProjectAccessTest extends ProjectTestCase
{
    use RefreshDatabase;

    /** §1/§6: everything /welcome offers, opens. */
    public function test_every_project_the_welcome_sidebar_offers_can_actually_be_opened(): void
    {
        [$owner, $workspace] = $this->owner();
        $alpha = $this->makeProject($owner, $workspace, ['name' => 'Project Alpha', 'identifier' => 'alpha']);
        $this->makeProject($owner, $workspace, ['name' => 'Project Beta', 'identifier' => 'beta']);

        $mike = $this->member($workspace, 'member', 'mike@example.com');
        $workspace->run(fn () => ProjectMember::create([
            'project_id' => $alpha->id, 'user_id' => $mike->id, 'role' => ProjectMember::ROLE_CONTRIBUTOR,
        ]));

        $offered = $this->actingAs($mike)->get(route('welcome'))
            ->assertOk()->viewData('projects');

        // Beta is public and Mike was never added to it, so it must not be offered at all.
        $this->assertSame(['Project Alpha'], array_column($offered, 'name'));

        foreach ($offered as $row) {
            $this->actingAs($mike)->get($row['url'])->assertRedirect();
        }
    }

    /** §2/§6: the Projects page and the sidebar beside it list the same set. */
    public function test_the_projects_page_and_the_sidebar_agree(): void
    {
        [$owner, $workspace] = $this->owner();
        $alpha = $this->makeProject($owner, $workspace, ['name' => 'Project Alpha', 'identifier' => 'alpha']);
        $this->makeProject($owner, $workspace, ['name' => 'Project Beta', 'identifier' => 'beta']);
        $gamma = $this->makeProject($owner, $workspace, ['name' => 'Project Gamma', 'identifier' => 'gamma']);

        $mike = $this->member($workspace, 'member', 'mike@example.com');
        $workspace->run(function () use ($alpha, $gamma, $mike) {
            foreach ([$alpha, $gamma] as $project) {
                ProjectMember::create([
                    'project_id' => $project->id, 'user_id' => $mike->id,
                    'role' => ProjectMember::ROLE_CONTRIBUTOR,
                ]);
            }
        });

        $response = $this->actingAs($mike)->get(route('projects.index'))->assertOk();

        $listed = array_column($response->viewData('bootstrap')['projects'], 'name');
        $sidebar = array_column($response->viewData('projects'), 'name');

        sort($listed);
        sort($sidebar);

        $this->assertSame(['Project Alpha', 'Project Gamma'], $listed);
        $this->assertSame($listed, $sidebar);
    }

    /** §3: the CTA follows the permission, on every screen that renders the sidebar. */
    public function test_a_member_is_not_offered_add_project_anywhere(): void
    {
        [$owner, $workspace] = $this->owner();
        $project = $this->makeProject($owner, $workspace, ['identifier' => 'web']);

        $mike = $this->member($workspace, 'member', 'mike@example.com');
        $workspace->run(fn () => ProjectMember::create([
            'project_id' => $project->id, 'user_id' => $mike->id, 'role' => ProjectMember::ROLE_CONTRIBUTOR,
        ]));

        foreach (['welcome', 'projects.index'] as $route) {
            $this->assertFalse(
                $this->actingAs($mike)->get(route($route))->assertOk()->viewData('canCreateProject'),
                "{$route} offered Add Project to a Member",
            );
        }

        // And the owner still is.
        $this->assertTrue($this->actingAs($owner)->get(route('welcome'))->viewData('canCreateProject'));
    }

    /** §4/§5: the same person, two workspaces, two different answers. */
    public function test_owning_one_workspace_grants_nothing_in_another(): void
    {
        [$owner, $abc] = $this->owner('abc-inc');
        $mike = $this->member($abc, 'member', 'mike@example.com');

        $mine = app(WorkspaceCreator::class)->create($mike->fresh(), [
            'name' => 'Mike Workspace', 'slug' => 'mike-ws', 'company_size' => '2-10',
        ]);
        $mike = $mike->fresh();

        // Owner in his own, Member in theirs — and neither leaks into the other.
        $this->assertTrue($mike->can('create', [Project::class, $mine]));
        $this->assertFalse($mike->can('create', [Project::class, $abc]));
        $this->assertFalse($owner->can('create', [Project::class, $mine]));
    }
}
