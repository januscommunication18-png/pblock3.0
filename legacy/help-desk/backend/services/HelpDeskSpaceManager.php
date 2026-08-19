<?php

namespace App\Services\HelpDesk;

use App\Models\HelpDesk;
use App\Models\HelpDeskInbox;
use App\Models\HelpDeskSpace;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creating spaces and deciding which inboxes belong to them
 * (Workspace & Inbox Assignment requirements §5, §6, §7, §8, §11, §12).
 *
 * The rule the whole file exists to keep is §7's: **one inbox belongs to one space**. Assigning
 * an inbox to a space is therefore always a MOVE — there is no "add", because the inbox was
 * already somewhere (or nowhere) and it cannot be in two places. Every write below goes through
 * `assign()` for that reason, so the rule is enforced once rather than restated at each screen
 * that can trigger it: the create page, Assign Inbox, the inbox's own settings, and creating an
 * inbox from inside a space.
 *
 * What a move deliberately does NOT touch is what §7 promises in as many words: the inbox keeps
 * its conversations, its contacts, its routing and its email configuration. That promise is kept
 * by construction rather than by care — all of those hang off the inbox, and this only ever
 * writes one column on the inbox itself.
 */
class HelpDeskSpaceManager
{
    public function __construct(private readonly HelpDeskActivityRecorder $activity) {}

    /**
     * Create a space, optionally with inboxes assigned to it in the same act (§5, §6).
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<int, int>  $inboxIds
     */
    public function create(HelpDesk $helpDesk, ?User $actor, array $attributes, array $inboxIds = []): HelpDeskSpace
    {
        return DB::transaction(function () use ($helpDesk, $actor, $attributes, $inboxIds) {
            $space = $this->guardDuplicateName(fn () => HelpDeskSpace::create($attributes + [
                'tenant_id' => $helpDesk->tenant_id,
                'help_desk_id' => $helpDesk->id,
                'created_by' => $actor?->id,
            ]));

            $this->activity->spaceCreated($helpDesk, $actor, $space);

            $this->assign($helpDesk, $space, $inboxIds, $actor);

            return $space->fresh();
        });
    }

    /**
     * Rename or re-describe a space (§8's Edit).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(HelpDesk $helpDesk, HelpDeskSpace $space, ?User $actor, array $attributes): HelpDeskSpace
    {
        $from = (string) $space->name;

        $this->guardDuplicateName(fn () => $space->forceFill($attributes)->save());

        if ($from !== $space->name) {
            $this->activity->spaceRenamed($helpDesk, $actor, $space, $from);
        }

        return $space->fresh();
    }

    /**
     * Put these inboxes in this space, taking them from wherever they are (§6, §7, §12).
     *
     * Idempotent per inbox: one already here is skipped rather than re-recorded, so the Assign
     * Inbox dialog can be saved twice without writing two identical entries into the history.
     *
     * @param  array<int, int>  $inboxIds
     * @return int how many actually moved
     */
    public function assign(HelpDesk $helpDesk, ?HelpDeskSpace $space, array $inboxIds, ?User $actor = null): int
    {
        $inboxIds = array_values(array_unique(array_filter(array_map('intval', $inboxIds))));

        if ($inboxIds === []) {
            return 0;
        }

        if ($space && $space->isArchived()) {
            // An archived space is one somebody has finished with. Moving live inboxes into it
            // would be filing them where nothing looks.
            throw ValidationException::withMessages([
                'space' => 'That space is archived. Restore it before assigning inboxes to it.',
            ]);
        }

        /*
         * Read WITH the Help Desk constraint rather than trusting the ids: these arrive from a
         * multi-select, and an inbox id from another Help Desk is the one mistake this call can
         * make that would move somebody else's inbox.
         */
        $inboxes = HelpDeskInbox::query()
            ->where('help_desk_id', $helpDesk->id)
            ->whereIn('id', $inboxIds)
            ->with('space')
            ->get();

        $moved = 0;

        foreach ($inboxes as $inbox) {
            $fromId = $inbox->help_desk_space_id;
            $toId = $space?->id;

            if ((int) $fromId === (int) $toId) {
                continue;
            }

            $from = $inbox->space?->name;

            $inbox->forceFill(['help_desk_space_id' => $toId])->save();

            $this->activity->inboxAssigned($helpDesk, $actor, $inbox, $from, $space?->name);

            $moved++;
        }

        return $moved;
    }

    /** Take an inbox out of every space (§12's "not yet been assigned", chosen deliberately). */
    public function unassign(HelpDesk $helpDesk, HelpDeskInbox $inbox, ?User $actor = null): void
    {
        $this->assign($helpDesk, null, [$inbox->id], $actor);
    }

    /**
     * Archive a space (§8).
     *
     * Refused while it still holds inboxes, and that refusal is the feature: archiving a space
     * with three inboxes and five hundred conversations would either orphan them silently or
     * hide them, and both are worse than being told to move them first. It is the same position
     * H21 takes on deleting an inbox — the question "what happens to what is inside?" gets an
     * answer, not a default.
     */
    public function archive(HelpDesk $helpDesk, HelpDeskSpace $space, ?User $actor = null): HelpDeskSpace
    {
        $held = HelpDeskInbox::query()->where('help_desk_space_id', $space->id)->count();

        if ($held > 0) {
            throw ValidationException::withMessages([
                'space' => $held === 1
                    ? 'Move its inbox to another space before archiving this one.'
                    : "Move its {$held} inboxes to another space before archiving this one.",
            ]);
        }

        $space->forceFill(['archived_at' => now()])->save();

        $this->activity->spaceArchived($helpDesk, $actor, $space);

        return $space->fresh();
    }

    public function restore(HelpDesk $helpDesk, HelpDeskSpace $space, ?User $actor = null): HelpDeskSpace
    {
        $space->forceFill(['archived_at' => null])->save();

        $this->activity->spaceRestored($helpDesk, $actor, $space);

        return $space->fresh();
    }

    /**
     * Turn the unique-name constraint into a field error.
     *
     * The database owns the rule because two administrators can create "Partner Support" in the
     * same instant and a pre-flight query cannot see the other one — the same reason
     * InboxController::guardDuplicate exists.
     */
    private function guardDuplicateName(callable $write): mixed
    {
        try {
            return $write();
        } catch (QueryException $e) {
            if (! str_contains(strtolower($e->getMessage()), 'unique')) {
                throw $e;
            }

            throw ValidationException::withMessages([
                'name' => 'A space with that name already exists.',
            ]);
        }
    }
}
