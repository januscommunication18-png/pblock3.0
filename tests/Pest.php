<?php

use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\ProjectCreator;
use App\Services\ProjectItemStateProvisioner;
use App\Services\WorkspaceCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Pest
|--------------------------------------------------------------------------
| Pest sits ALONGSIDE the existing PHPUnit suite rather than replacing it: it runs PHPUnit
| test classes unchanged, so the ~580 feature tests already here keep working exactly as they
| did, and Pest's own syntax is used for the new end-to-end tests.
|
| Rewriting a passing suite to change its syntax would be churn with no behavioural payoff —
| the gap worth closing is that none of those tests open a browser.
*/

pest()->extend(TestCase::class)->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| End-to-end
|--------------------------------------------------------------------------
| Browser tests drive a REAL browser against a REAL server. The plugin runs that server
| IN-PROCESS, which is what makes RefreshDatabase usable here: the request the browser makes is
| handled by this same PHP process, on this same connection, so it sees the rows the test just
| created. A suite that had to seed a separate database would be a fixture-management project
| rather than a test.
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Browser');

/*
|--------------------------------------------------------------------------
| End-to-end helpers
|--------------------------------------------------------------------------
| These tests drive a real browser against a real server, which is the whole point: every
| bug this session shipped and had to come back for was invisible to the HTTP suite —
| icons that never rendered, a `hidden` class Font Awesome overrode, a grid that drew no
| rows, an editor that stopped tracking changes. All of those are things you can only see
| by looking at a page.
|
| Data is built through the app's own services rather than factories, so a browser test
| exercises the same creation paths the application does.
*/

/**
 * A signed-in owner with a workspace and a project ready to use.
 *
 * @return array{0: User, 1: Workspace, 2: Project}
 */
function e2eWorkspace(string $slug = 'e2e'): array
{
    $user = User::factory()->create([
        'full_name' => 'Rohit Philip',
        'email' => $slug.'-'.uniqid().'@example.com',
    ]);

    $workspace = app(WorkspaceCreator::class)->create($user, [
        'name' => 'E2E Workspace', 'slug' => $slug.'-'.uniqid(), 'company_size' => '2-10',
    ]);

    $project = $workspace->run(function () use ($user, $workspace) {
        $project = app(ProjectCreator::class)->create($user, $workspace, [
            'name' => 'Ecom Help Desk',
            'identifier' => 'E2E'.random_int(100, 999),
            'description' => null,
            'visibility' => Project::VISIBILITY_PUBLIC,
            'lead_user_id' => null,
        ]);

        // Work item states are provisioned on first use; a browser test should not be the
        // thing that discovers they are missing.
        app(ProjectItemStateProvisioner::class)->for($project);

        return $project;
    });

    return [$user->fresh(), $workspace, $project];
}

/** Add somebody to the workspace and the project, so they are assignable and mentionable. */
function e2eTeammate(Workspace $workspace, Project $project, string $name, string $email): User
{
    $user = User::factory()->create(['full_name' => $name, 'display_name' => $name, 'email' => $email]);

    WorkspaceMembership::create([
        'workspace_id' => $workspace->id, 'user_id' => $user->id,
        'role' => 'member', 'status' => 'active', 'joined_at' => now(),
    ]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();

    $workspace->run(fn () => ProjectMember::create([
        'project_id' => $project->id, 'user_id' => $user->id, 'role' => ProjectMember::ROLE_CONTRIBUTOR,
    ]));

    return $user->fresh();
}
