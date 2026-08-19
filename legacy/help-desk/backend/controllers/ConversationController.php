<?php

namespace App\Http\Controllers\HelpDesk;

use App\Models\HelpDesk;
use App\Models\HelpDeskConversation;
use App\Models\HelpDeskInbox;
use App\Services\HelpDesk\ConversationRouter;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Conversations, as much of them as Phase 2 owns (docs/features/help-desk.md — slice 3:
 * FR-2.8 manual routing, and the visible half of FR-2.9).
 *
 * A LIST, not a conversation workspace — reading and replying are Phase 3. What is here is what
 * Phase 2's acceptance criteria need to be true of: agents can move a conversation between
 * authorized inboxes, unauthorized members cannot view inbox content, and a failed delivery is
 * visible rather than silent.
 *
 * Every read is confined to the inboxes the person may open (FR-2.6). That is not a filter for
 * convenience — it is the authorization, applied in the query rather than after it, so there is
 * no path where a conversation is loaded and then hidden.
 */
class ConversationController extends AreaController
{
    /** §13: paginate conversation lists. */
    private const PER_PAGE = 25;

    /** GET /help-desk/conversations */
    public function index(Request $request): View
    {
        $helpDesk = $this->helpDesk();

        /*
         * Two narrowings, in this order, and they mean different things.
         *
         * `$allowed` is AUTHORIZATION — the inboxes this person may open (FR-2.6). `$space` is
         * CONTEXT — the space they are currently working in (Workspace & Inbox Assignment §13,
         * "selecting Partner Support shows only partner support conversations"). Intersecting
         * them rather than replacing one with the other is what keeps a context from ever
         * widening what somebody can see: a space you are in cannot show you an inbox you may
         * not open.
         */
        $allowed = $this->intersect(
            $this->access->inboxIds($this->user(), $this->workspace()),
            $this->spaceInboxIds(),
        );

        $inboxes = $this->openableInboxes($helpDesk, $allowed);

        // A named inbox filters the list; anything not open to this person is ignored rather
        // than refused, because it is a URL somebody can edit, not a decision they made.
        $selected = (int) $request->integer('inbox');
        $selected = array_key_exists($selected, $inboxes) ? $selected : 0;

        $conversations = HelpDeskConversation::query()
            ->where('help_desk_id', $helpDesk->id)
            ->when($allowed !== null, fn ($q) => $q->whereIn('help_desk_inbox_id', $allowed ?: [0]))
            ->when($selected, fn ($q) => $q->where('help_desk_inbox_id', $selected))
            // Eager-loaded: a page of conversations otherwise asks for an inbox and an assignee
            // per row (§13, no N+1 in list views).
            ->with(['inbox', 'assignee.user'])
            ->withCount('messages')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (HelpDeskConversation $c) => [
                'id' => $c->id,
                'reference' => $c->reference(),
                'subject' => $c->subject ?: '(no subject)',
                'customer' => $c->customer_name ?: $c->customer_email,
                'customer_email' => $c->customer_email,
                'inbox_id' => $c->help_desk_inbox_id,
                'inbox' => $c->inbox?->name,
                'assignee' => $c->assignee?->user?->displayName(),
                'messages' => $c->messages_count,
                'last_message' => optional($c->last_message_at)->format('M d, Y · H:i'),
                // FR-2.9's "visible": the row itself says the customer never heard.
                'delivery_failed' => $c->hasDeliveryFailure(),
            ]);

        return $this->page('conversations', [
            'conversations' => $conversations->items(),
            'inboxes' => array_values($inboxes),
            'selected' => $selected,
            'canMove' => $this->access->allows($this->user(), $this->workspace(), 'move'),
            'endpoints' => [
                'move' => route('help-desk.conversations.move', ['conversation' => '__ID__']),
            ],
        ], [
            'helpDesk' => $helpDesk,
            'paginator' => $conversations,
            'selected' => $selected,
        ]);
    }

    /**
     * PATCH /help-desk/conversations/{conversation}/inbox — move it (FR-2.8).
     *
     * Both ends are authorized, not just the destination: moving a conversation OUT of an inbox
     * you cannot open would be a way to read what is in it, and moving one INTO an inbox you
     * cannot open would be a way to hide it.
     */
    public function move(Request $request, HelpDeskConversation $conversation, ConversationRouter $router): JsonResponse
    {
        $helpDesk = $this->helpDesk();
        abort_unless((int) $conversation->help_desk_id === (int) $helpDesk->id, 404);

        $validated = $request->validate(['inbox_id' => ['required', 'integer']]);

        $target = HelpDeskInbox::query()
            ->where('help_desk_id', $helpDesk->id)
            ->whereKey($validated['inbox_id'])
            ->first();

        abort_unless($target !== null, 404);

        abort_unless($this->user()->can('move', [$conversation, $target]), 403);

        $moved = $router->move($conversation, $target, $this->user());

        return response()->json([
            'ok' => true,
            'inbox_id' => $moved->help_desk_inbox_id,
            'inbox' => $target->name,
            'assignee' => $moved->assignee?->user?->displayName(),
        ]);
    }

    /**
     * Two inbox-id constraints, combined.
     *
     * `null` on either side means "not narrowed by this one" — for authorization that is a role
     * reaching every inbox, and for context it is no space selected. Two nulls stay null; one
     * null takes the other; two lists intersect. An EMPTY list is never treated as null: a
     * member given no inboxes, and a space holding none, both mean nothing rather than
     * everything, and that is the distinction a bug here would erase.
     *
     * @param  array<int, int>|null  $allowed
     * @param  array<int, int>|null  $space
     * @return array<int, int>|null
     */
    private function intersect(?array $allowed, ?array $space): ?array
    {
        if ($allowed === null) {
            return $space;
        }

        if ($space === null) {
            return $allowed;
        }

        return array_values(array_intersect($allowed, $space));
    }

    /**
     * The inboxes this person may open, keyed by id (FR-2.6).
     *
     * @param  array<int, int>|null  $allowed  null means every inbox
     * @return array<int, array<string, mixed>>
     */
    private function openableInboxes(HelpDesk $helpDesk, ?array $allowed): array
    {
        return HelpDeskInbox::query()
            ->where('help_desk_id', $helpDesk->id)
            ->when($allowed !== null, fn ($q) => $q->whereIn('id', $allowed ?: [0]))
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (HelpDeskInbox $i) => [$i->id => ['id' => $i->id, 'name' => $i->name]])
            ->all();
    }
}
