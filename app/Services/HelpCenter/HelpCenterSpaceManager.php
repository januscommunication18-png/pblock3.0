<?php

namespace App\Services\HelpCenter;

use App\Models\HelpCenterSpace;
use App\Models\User;

/**
 * Creating a Space (docs/features/help-center.md §3, §4).
 *
 * Small, but it is the one place a Space comes into existence — the wizard's step 1 and the
 * navigation's "Spaces +" are two doors into it (§4), and they must not each decide what a new
 * Space looks like.
 */
class HelpCenterSpaceManager
{
    /**
     * @param  array{name: string, description?: ?string, types: array<int, string>, lead_user_id: int|string}  $data
     */
    public function create(User $actor, array $data): HelpCenterSpace
    {
        return HelpCenterSpace::create([
            'name' => trim($data['name']),
            'description' => $this->description($data['description'] ?? null),
            // Normalized again here, not only in the request: this service is the one place a
            // Space comes into existence, and it should not depend on its caller having tidied
            // the input (§3).
            'types' => HelpCenterSpace::normalizeTypes((array) ($data['types'] ?? [])),
            'lead_user_id' => $data['lead_user_id'],
            'created_by' => $actor->id,
            // Appended, so a new Space lands at the end of an order somebody has already
            // arranged rather than jumping to the top of the navigation.
            'position' => (int) HelpCenterSpace::query()->max('position') + 1,
        ]);
    }

    /**
     * An empty description is NULL, not "".
     *
     * The column is nullable and the difference matters when reading: `''` renders as an empty
     * paragraph where `null` renders as nothing at all.
     */
    private function description(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
