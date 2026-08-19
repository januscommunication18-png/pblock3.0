<?php

namespace App\Services\HelpDesk;

use App\Models\User;
use App\Models\Workspace;

/**
 * Which space the Help Desk is currently showing (Workspace & Inbox Assignment §13).
 *
 * "Changing Workspace updates the Help Center context" — selecting Partner Support shows partner
 * inboxes and partner conversations and nothing else. That selection is held in the SESSION,
 * keyed per workspace, for the same reason the workspace switcher itself is a stored choice
 * rather than a query string: it is a place you are, not a filter you applied, and it has to
 * survive following a link.
 *
 * Two things this class refuses to let go wrong:
 *
 *   1. a stored id is re-checked against what this person may actually open on EVERY read. A
 *      session value is a thing a browser holds; access can be taken away while it holds it, and
 *      a context that outlived its permission would scope screens to a space nobody may see;
 *   2. "no selection" is a real state — everything the person can reach — and not a null that
 *      each caller invents an answer for. §13's switcher lists the spaces; this adds the case
 *      where somebody wants to see across them, which the Spaces screen itself requires.
 */
class HelpDeskSpaceContext
{
    /** Session key, per workspace: two workspaces' Help Desks must not share a selection. */
    private const KEY = 'help_desk.space';

    public function __construct(private readonly HelpDeskAccess $access) {}

    /**
     * The space in context, or null for "everything I can reach".
     *
     * Null is returned rather than a default space deliberately: dropping somebody into one of
     * several spaces on their first visit would hide the others behind a control they have not
     * noticed yet.
     */
    public function current(?User $user, ?Workspace $workspace): ?int
    {
        $stored = (int) session($this->key($workspace), 0);

        if ($stored === 0) {
            return null;
        }

        // Re-checked every time, not on the way in: access can be withdrawn while a session
        // holds the value it was granted under.
        if (! $this->access->canOpenSpace($user, $workspace, $stored)) {
            $this->forget($workspace);

            return null;
        }

        return $stored;
    }

    /**
     * Switch to a space, or to null for all of them.
     *
     * Returns whether the switch was allowed, so the caller can say "that space is not yours"
     * rather than silently leaving the person where they were and looking broken.
     */
    public function set(?User $user, ?Workspace $workspace, ?int $spaceId): bool
    {
        if ($spaceId === null || $spaceId === 0) {
            $this->forget($workspace);

            return true;
        }

        if (! $this->access->canOpenSpace($user, $workspace, $spaceId)) {
            return false;
        }

        session([$this->key($workspace) => $spaceId]);

        return true;
    }

    public function forget(?Workspace $workspace): void
    {
        session()->forget($this->key($workspace));
    }

    private function key(?Workspace $workspace): string
    {
        return self::KEY.'.'.($workspace?->id ?? 'none');
    }
}
