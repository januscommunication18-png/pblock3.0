<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Collection;

/**
 * Who may be named in a given project (mentions §16).
 *
 * ONE definition, used by both halves of the feature — the autocomplete that suggests people
 * and the validation that decides whether a submitted id counts. They must agree: a list that
 * offers somebody the backend will then reject is a broken feature, and the reverse is a
 * permission hole. §23 makes the backend the authority; this is what it consults.
 *
 * The rule matches ProjectNavigation::visible(): workspace Owner/Admin reach every project,
 * everyone else reaches the projects they were explicitly added to (§38). "Do not expose
 * workspace users who have no access to the project" is the requirement, so membership of the
 * workspace alone is not enough.
 */
class MentionableUsers
{
    /**
     * Everyone mentionable in this project.
     *
     * @return Collection<int, User>
     */
    public function for(Project $project): Collection
    {
        $administrators = WorkspaceMembership::query()
            ->where('workspace_id', $project->tenant_id)
            ->where('status', WorkspaceMembership::STATUS_ACTIVE)
            ->whereIn('role', [WorkspaceMembership::ROLE_OWNER, 'admin'])
            ->pluck('user_id');

        $members = ProjectMember::query()
            ->where('project_id', $project->id)
            ->pluck('user_id');

        $ids = $administrators->merge($members)->unique();

        if ($ids->isEmpty()) {
            return collect();
        }

        // Still an active member of the workspace: someone removed from the company is not
        // mentionable in it, whatever project rows they left behind.
        $active = WorkspaceMembership::query()
            ->where('workspace_id', $project->tenant_id)
            ->where('status', WorkspaceMembership::STATUS_ACTIVE)
            ->whereIn('user_id', $ids)
            ->pluck('user_id');

        return User::whereIn('id', $active)->orderBy('full_name')->get();
    }

    /**
     * The autocomplete's answer: matching people, shaped for the editor (§4, §6).
     *
     * Searching name AND email (§5) — people look each other up by whichever they remember,
     * and both are already visible to anyone who can see the member list.
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(Project $project, ?string $term, int $limit): array
    {
        $needle = trim((string) $term);

        return $this->for($project)
            ->filter(function (User $user) use ($needle) {
                if ($needle === '') {
                    return true;
                }

                $haystack = mb_strtolower($user->displayName().' '.$user->full_name.' '.$user->email);

                return str_contains($haystack, mb_strtolower($needle));
            })
            // §28: a bounded list. A workspace with two thousand people must not send two
            // thousand rows because somebody typed "a".
            ->take($limit)
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->displayName(),
                'email' => $user->email,
                'initial' => $user->initial(),
                'avatar_url' => $user->avatar_url,
                // The fallback disc colour, so the popup's faces match the rest of the app.
                'avatar_color' => $user->avatarColor(),
            ])
            ->values()
            ->all();
    }

    /**
     * Which of these ids may actually be mentioned here (§24).
     *
     * @param  array<int, int>  $ids
     * @return Collection<int, int>
     */
    public function filterIds(Project $project, array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return $this->for($project)
            ->pluck('id')
            ->intersect($ids)
            ->values();
    }
}
