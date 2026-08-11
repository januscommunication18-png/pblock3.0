<?php

namespace Tests\Feature\Project;

use App\Models\ProjectActivity;
use App\Models\ProjectMember;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Project Settings → Members (Project Member Management, §35 acceptance criteria).
 *
 * The governing rule is §38: workspace membership says you belong to the organisation,
 * project membership says you participate in THIS project and what you may do inside it.
 */
class ProjectMemberManagementTest extends ProjectTestCase
{
    use RefreshDatabase;

    /** AC-01: an authorized Project Admin sees the project's members. */
    public function test_members_screen_lists_members_with_both_role_layers(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'WEB']);
        $sarah = $this->member($ws, 'manager', 'sarah@example.com');

        $this->actingAs($owner)->postJson(route('projects.settings.members.store', $project), [
            'user_id' => $sarah->id, 'role' => ProjectMember::ROLE_CONTRIBUTOR,
        ])->assertOk();

        $this->actingAs($owner)
            ->get(route('projects.settings', ['project' => $project->id, 'section' => 'members']))
            ->assertOk()
            ->assertViewHas('bootstrap', function ($b) use ($owner, $sarah) {
                $by = collect($b['members'])->keyBy('user_id');

                // §6: the row carries the workspace role AND the project role.
                return $by[$owner->id]['role'] === ProjectMember::ROLE_ADMIN
                    && $by[$owner->id]['workspace_role'] === 'owner'
                    && $by[$sarah->id]['role'] === ProjectMember::ROLE_CONTRIBUTOR
                    && $by[$sarah->id]['workspace_role'] === 'manager'
                    && $by[$sarah->id]['added_by'] === $owner->displayName();
            });
    }

    /** AC-02 / AC-03: the picker offers active coworkers, minus those already on the project. */
    public function test_candidates_exclude_existing_members(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'WEB']);
        $sarah = $this->member($ws, 'member', 'sarah@example.com');
        $mark = $this->member($ws, 'guest', 'mark@example.com');

        $this->actingAs($owner)
            ->get(route('projects.settings', ['project' => $project->id, 'section' => 'members']))
            ->assertViewHas('bootstrap', function ($b) use ($owner, $sarah, $mark) {
                $ids = array_column($b['candidates'], 'id');

                // The owner is already a member (§19), so only the other two are offered.
                return ! in_array($owner->id, $ids, true)
                    && in_array($sarah->id, $ids, true)
                    && in_array($mark->id, $ids, true);
            });

        // Once added she leaves the candidate list (but of course appears under members).
        $response = $this->actingAs($owner)
            ->postJson(route('projects.settings.members.store', $project), [
                'user_id' => $sarah->id, 'role' => ProjectMember::ROLE_CONTRIBUTOR,
            ])->assertOk();

        $this->assertNotContains($sarah->id, array_column($response->json('candidates'), 'id'));
        $this->assertContains($sarah->id, array_column($response->json('members'), 'user_id'));
    }

    /** AC-04 / AC-05: every project role can be assigned and appears immediately. */
    public function test_each_project_role_can_be_assigned(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'WEB']);

        foreach (['admin', 'contributor', 'commenter', 'guest'] as $i => $role) {
            $user = $this->member($ws, 'member', "role{$i}@example.com");

            $this->actingAs($owner)
                ->postJson(route('projects.settings.members.store', $project), [
                    'user_id' => $user->id, 'role' => $role,
                ])
                ->assertOk()
                ->assertJsonPath('message', 'Member added to project successfully.')
                ->assertJsonFragment(['user_id' => $user->id, 'role' => $role]);
        }
    }

    /** AC-12 / §26: someone from another workspace can never be added. */
    public function test_a_user_from_another_workspace_cannot_be_added(): void
    {
        [$owner, $ws] = $this->owner('ws-a');
        [$outsider] = $this->owner('ws-b');
        $project = $this->makeProject($owner, $ws, ['identifier' => 'WEB']);

        $this->actingAs($owner)
            ->postJson(route('projects.settings.members.store', $project), [
                'user_id' => $outsider->id, 'role' => ProjectMember::ROLE_CONTRIBUTOR,
            ])
            ->assertStatus(422)->assertJsonValidationErrors('user_id');

        $this->assertSame(1, $ws->run(fn () => ProjectMember::where('project_id', $project->id)->count()));
    }

    /** §25: one membership row per person per project. */
    public function test_a_coworker_cannot_be_added_twice(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'WEB']);
        $sarah = $this->member($ws, 'member', 'sarah@example.com');

        $payload = ['user_id' => $sarah->id, 'role' => ProjectMember::ROLE_CONTRIBUTOR];
        $this->actingAs($owner)->postJson(route('projects.settings.members.store', $project), $payload)->assertOk();
        $this->actingAs($owner)->postJson(route('projects.settings.members.store', $project), $payload)
            ->assertStatus(422)->assertJsonValidationErrors('user_id');
    }

    /** AC-07: a Project Admin can change a member's role. */
    public function test_project_role_can_be_changed(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'WEB']);
        $sarah = $this->member($ws, 'member', 'sarah@example.com');

        $this->actingAs($owner)->postJson(route('projects.settings.members.store', $project), [
            'user_id' => $sarah->id, 'role' => ProjectMember::ROLE_COMMENTER,
        ])->assertOk();
        $member = $ws->run(fn () => ProjectMember::where('user_id', $sarah->id)->first());

        $this->actingAs($owner)
            ->patchJson(route('projects.settings.members.role', ['project' => $project->id, 'member' => $member->id]), [
                'role' => ProjectMember::ROLE_ADMIN,
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Project role updated successfully.');

        $this->assertSame(ProjectMember::ROLE_ADMIN, $ws->run(fn () => ProjectMember::find($member->id)->role));
    }

    /** AC-08 / AC-09: removal takes project access only, never workspace membership. */
    public function test_removing_a_member_leaves_them_in_the_workspace(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'WEB']);
        $sarah = $this->member($ws, 'member', 'sarah@example.com');

        $this->actingAs($owner)->postJson(route('projects.settings.members.store', $project), [
            'user_id' => $sarah->id, 'role' => ProjectMember::ROLE_CONTRIBUTOR,
        ])->assertOk();
        $member = $ws->run(fn () => ProjectMember::where('user_id', $sarah->id)->first());

        $this->actingAs($owner)
            ->deleteJson(route('projects.settings.members.remove', ['project' => $project->id, 'member' => $member->id]))
            ->assertOk()
            ->assertJsonPath('message', 'Member removed from project.');

        $this->assertFalse($ws->run(fn () => ProjectMember::whereKey($member->id)->exists()));
        // Still a workspace member (§14) — and the project is no longer visible to them (§38).
        $this->assertDatabaseHas('workspace_memberships', [
            'workspace_id' => $ws->id, 'user_id' => $sarah->id, 'status' => 'active',
        ]);
        $this->actingAs($sarah)->get(route('projects.work-items', $project))->assertNotFound();
    }

    /** AC-13 / §20: the final Project Admin cannot be demoted or removed. */
    public function test_the_last_project_admin_is_protected(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'WEB']);
        $ownerMember = $ws->run(fn () => ProjectMember::where('user_id', $owner->id)->first());

        $this->actingAs($owner)
            ->patchJson(route('projects.settings.members.role', ['project' => $project->id, 'member' => $ownerMember->id]), [
                'role' => ProjectMember::ROLE_CONTRIBUTOR,
            ])
            ->assertStatus(422)->assertJsonValidationErrors('role');

        $this->actingAs($owner)
            ->deleteJson(route('projects.settings.members.remove', ['project' => $project->id, 'member' => $ownerMember->id]))
            ->assertStatus(422);

        // With a second Admin in place, the first may step down.
        $sarah = $this->member($ws, 'member', 'sarah@example.com');
        $this->actingAs($owner)->postJson(route('projects.settings.members.store', $project), [
            'user_id' => $sarah->id, 'role' => ProjectMember::ROLE_ADMIN,
        ])->assertOk();

        $this->actingAs($owner)
            ->patchJson(route('projects.settings.members.role', ['project' => $project->id, 'member' => $ownerMember->id]), [
                'role' => ProjectMember::ROLE_CONTRIBUTOR,
            ])
            ->assertOk();
    }

    /** AC-10 / AC-11 / §17: only Owner, Workspace Admin or Project Admin may manage members. */
    public function test_only_authorized_users_can_manage_members(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'WEB']);
        $target = $this->member($ws, 'member', 'target@example.com');

        // A project Contributor may open the project but must not manage its members.
        $contributor = $this->member($ws, 'member', 'contrib@example.com');
        $ws->run(fn () => ProjectMember::create([
            'project_id' => $project->id, 'user_id' => $contributor->id,
            'role' => ProjectMember::ROLE_CONTRIBUTOR,
        ]));

        $this->actingAs($contributor)
            ->postJson(route('projects.settings.members.store', $project), [
                'user_id' => $target->id, 'role' => ProjectMember::ROLE_CONTRIBUTOR,
            ])
            ->assertForbidden();

        // §17: a Workspace Manager qualifies only when they are also this project's Admin.
        $manager = $this->member($ws, 'manager', 'manager@example.com');
        $this->actingAs($manager)
            ->postJson(route('projects.settings.members.store', $project), [
                'user_id' => $target->id, 'role' => ProjectMember::ROLE_CONTRIBUTOR,
            ])
            ->assertForbidden();

        $ws->run(fn () => ProjectMember::create([
            'project_id' => $project->id, 'user_id' => $manager->id, 'role' => ProjectMember::ROLE_ADMIN,
        ]));
        $this->actingAs($manager)
            ->postJson(route('projects.settings.members.store', $project), [
                'user_id' => $target->id, 'role' => ProjectMember::ROLE_CONTRIBUTOR,
            ])
            ->assertOk();
    }

    /** §34: Commenter and Guest read work items but cannot create them. */
    public function test_project_role_governs_work_item_creation(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'WEB']);

        $expected = [
            ProjectMember::ROLE_ADMIN => 201,
            ProjectMember::ROLE_CONTRIBUTOR => 201,
            ProjectMember::ROLE_COMMENTER => 403,
            ProjectMember::ROLE_GUEST => 403,
        ];

        foreach ($expected as $role => $status) {
            $user = $this->member($ws, 'member', "{$role}@example.com");
            $ws->run(fn () => ProjectMember::create([
                'project_id' => $project->id, 'user_id' => $user->id, 'role' => $role,
            ]));

            // Every role can READ the project they belong to...
            $this->actingAs($user)->get(route('projects.work-items', $project))->assertOk();
            // ...but only Admin and Contributor may add work.
            $this->actingAs($user)
                ->postJson(route('projects.work-items.store', $project), ['title' => "By {$role}"])
                ->assertStatus($status);
        }
    }

    /** AC-14 / §19: the creator is seeded as Project Admin without asking. */
    public function test_the_project_creator_becomes_project_admin(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'WEB']);

        $member = $ws->run(fn () => ProjectMember::where('project_id', $project->id)
            ->where('user_id', $owner->id)->first());

        $this->assertNotNull($member);
        $this->assertSame(ProjectMember::ROLE_ADMIN, $member->role);
        $this->assertSame($owner->id, $member->added_by);
    }

    /** §29: membership changes are audited with actor, target and both roles. */
    public function test_membership_changes_are_audited(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'WEB']);
        $sarah = $this->member($ws, 'member', 'sarah@example.com');

        $this->actingAs($owner)->postJson(route('projects.settings.members.store', $project), [
            'user_id' => $sarah->id, 'role' => ProjectMember::ROLE_COMMENTER,
        ])->assertOk();
        $member = $ws->run(fn () => ProjectMember::where('user_id', $sarah->id)->first());

        $this->actingAs($owner)->patchJson(
            route('projects.settings.members.role', ['project' => $project->id, 'member' => $member->id]),
            ['role' => ProjectMember::ROLE_ADMIN],
        )->assertOk();

        $this->actingAs($owner)->deleteJson(
            route('projects.settings.members.remove', ['project' => $project->id, 'member' => $member->id]),
        )->assertOk();

        $events = $ws->run(fn () => ProjectActivity::where('project_id', $project->id)->orderBy('id')->get());

        $this->assertSame(
            [ProjectActivity::EVENT_MEMBER_ADDED, ProjectActivity::EVENT_MEMBER_ROLE_CHANGED, ProjectActivity::EVENT_MEMBER_REMOVED],
            $events->pluck('event')->all(),
        );
        $this->assertSame($owner->id, $events[0]->actor_id);
        $this->assertSame($sarah->id, $events[0]->target_user_id);
        $this->assertSame(ProjectMember::ROLE_COMMENTER, $events[1]->old_role);
        $this->assertSame(ProjectMember::ROLE_ADMIN, $events[1]->new_role);

        // §29's example sentence.
        $this->assertSame(
            "{$owner->displayName()} added {$sarah->displayName()} to Website Redesign as Commenter.",
            $ws->run(fn () => ProjectActivity::with(['actor', 'project'])->find($events[0]->id)->sentence()),
        );
    }

    /** §24: an unauthorized user must not reach the member-management screen at all. */
    public function test_unauthorized_users_cannot_open_the_members_screen(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'WEB']);
        $commenter = $this->member($ws, 'member', 'commenter@example.com');
        $ws->run(fn () => ProjectMember::create([
            'project_id' => $project->id, 'user_id' => $commenter->id, 'role' => ProjectMember::ROLE_COMMENTER,
        ]));

        $this->actingAs($commenter)
            ->get(route('projects.settings', ['project' => $project->id, 'section' => 'members']))
            ->assertForbidden();

        // They can still open the project itself — they are a member of it.
        $this->assertTrue($commenter->can('viewAny', [WorkItem::class, $project]));
    }

    /** The screen uses the same data grid as Workspace → Settings → Members. */
    public function test_members_screen_loads_the_shared_tabulator_grid(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['identifier' => 'WEB']);

        $response = $this->actingAs($owner)
            ->get(route('projects.settings', ['project' => $project->id, 'section' => 'members']))
            ->assertOk();

        // Same engine + shared skin as the workspace members listing, so the two screens
        // render identically rather than only looking similar.
        foreach (['tabulator.min.css', 'tabulator-skin.css', 'tabulator.min.js', 'projects/members.js'] as $asset) {
            $response->assertSee($asset, false);
        }
    }

    /** The settings shell names the project and offers a way back out. */
    public function test_settings_header_shows_the_project_name_and_a_back_link(): void
    {
        [$owner, $ws] = $this->owner();
        $project = $this->makeProject($owner, $ws, ['name' => 'Website Redesign', 'identifier' => 'WEB']);
        $back = route('projects.work-items', $project->id);

        $response = $this->actingAs($owner)
            ->get(route('projects.settings', ['project' => $project->id, 'section' => 'members']))
            ->assertOk()
            // Settings is a full-screen detour, so it needs a back affordance on the left.
            ->assertSee('title="Back to Website Redesign"', false)
            ->assertSee('href="'.e($back).'"', false);

        // The sidebar names WHOSE settings these are, not just "Project settings".
        $html = $response->getContent();
        $navAt = (int) strpos($html, 'Project settings');
        $this->assertStringContainsString('Website Redesign', substr($html, max(0, $navAt - 300), 300));
    }

    /** The workspace role vocabulary gained Manager (§25) without losing the existing ones. */
    public function test_manager_is_an_available_workspace_role(): void
    {
        $roles = config('workspace.roles');

        $this->assertArrayHasKey('manager', $roles);
        $this->assertContains('manager', config('workspace.invite_roles'));
        // Existing roles are untouched, so no live membership had to be migrated.
        foreach (['owner', 'admin', 'member', 'viewer', 'guest'] as $existing) {
            $this->assertArrayHasKey($existing, $roles);
        }
    }
}
