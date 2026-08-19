<?php

namespace App\Services\HelpCenter;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Collection;

/**
 * Who may be a Space Lead (docs/features/help-center.md §3).
 *
 * ONE definition, used by both halves of the feature — the searchable picker that offers people
 * and the validation that decides whether a submitted id counts. They must agree: a picker that
 * offers somebody the backend will then reject is a broken form, and the reverse is a
 * permission hole.
 *
 * "Only eligible Workspace members should be selectable" is the requirement. Eligible means an
 * ACTIVE member who is not a guest — a guest is external to the workspace, and §19's least
 * privileged role is still an internal one, so making one "the primary person responsible for
 * the Space" is not something the form should allow.
 */
class EligibleLeads
{
    /**
     * Everyone who may lead a Space in this workspace.
     *
     * @return Collection<int, User>
     */
    public function for(Workspace $workspace): Collection
    {
        $ids = WorkspaceMembership::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', WorkspaceMembership::STATUS_ACTIVE)
            ->where('role', '!=', 'guest')
            ->pluck('user_id');

        if ($ids->isEmpty()) {
            return collect();
        }

        return User::query()->whereIn('id', $ids)->orderBy('full_name')->get();
    }

    /** Is this specific person eligible? The validator's question. */
    public function includes(Workspace $workspace, int|string|null $userId): bool
    {
        if ($userId === null || $userId === '') {
            return false;
        }

        return WorkspaceMembership::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $userId)
            ->where('status', WorkspaceMembership::STATUS_ACTIVE)
            ->where('role', '!=', 'guest')
            ->exists();
    }

    /**
     * The picker's payload: avatar, name and email, as §3 asks for.
     *
     * @return array<int, array<string, mixed>>
     */
    public function options(Workspace $workspace): array
    {
        return $this->for($workspace)
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->displayName(),
                'email' => $user->email,
                'avatar' => $user->avatar_url,
                'initial' => mb_strtoupper(mb_substr($user->displayName(), 0, 1)),
                'color' => $user->avatarColor(),
            ])
            ->all();
    }
}
