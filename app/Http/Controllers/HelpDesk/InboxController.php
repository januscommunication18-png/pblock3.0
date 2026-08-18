<?php

namespace App\Http\Controllers\HelpDesk;

use App\Http\Requests\HelpDesk\StoreInboxRequest;
use App\Models\HelpDesk;
use App\Models\HelpDeskInbox;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Help Desk inboxes (docs/features/help-desk.md, FR-1.7) — minimal, by decision H4.
 *
 * Create and rename, and nothing else. Inbox-level access needs inboxes to exist and to be
 * nameable; DELETING one is a different question — what happens to the conversations in it —
 * and Phase 2, which gives an inbox its email channel and routing, is where that belongs.
 */
class InboxController extends AreaController
{
    /** POST /help-desk/settings/inboxes */
    public function store(StoreInboxRequest $request): JsonResponse
    {
        $helpDesk = $this->helpDesk();
        $this->guardAdminister($helpDesk);

        $inbox = $this->guardDuplicate(fn () => HelpDeskInbox::create([
            'tenant_id' => $helpDesk->tenant_id,
            'help_desk_id' => $helpDesk->id,
            'name' => trim((string) $request->validated('name')),
            'created_by' => $this->user()->id,
        ]));

        return $this->listResponse($helpDesk, ['inbox_id' => $inbox->id]);
    }

    /** PATCH /help-desk/settings/inboxes/{inbox} */
    public function update(StoreInboxRequest $request, HelpDeskInbox $inbox): JsonResponse
    {
        $helpDesk = $this->helpDesk();
        $this->guardAdminister($helpDesk);
        abort_unless((int) $inbox->help_desk_id === (int) $helpDesk->id, 404);

        $this->guardDuplicate(fn () => $inbox->forceFill([
            'name' => trim((string) $request->validated('name')),
        ])->save());

        return $this->listResponse($helpDesk);
    }

    /**
     * Turn the unique-name constraint into a message.
     *
     * The database owns the rule (unique per Help Desk) because two administrators can create
     * "Billing" at the same instant and a pre-flight query cannot see the other one; this is
     * what turns that collision into a field error rather than a 500.
     */
    private function guardDuplicate(callable $write): mixed
    {
        try {
            return $write();
        } catch (QueryException $e) {
            if (! str_contains(strtolower($e->getMessage()), 'unique')) {
                throw $e;
            }

            throw ValidationException::withMessages([
                'name' => 'An inbox with that name already exists.',
            ]);
        }
    }

    /** @param array<string, mixed> $extra */
    private function listResponse(HelpDesk $helpDesk, array $extra = []): JsonResponse
    {
        return response()->json(array_merge([
            'ok' => true,
            'inboxes' => $this->inboxRows($helpDesk),
            'members' => $this->memberRows($helpDesk),
        ], $extra));
    }
}
