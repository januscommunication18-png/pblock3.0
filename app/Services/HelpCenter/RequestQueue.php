<?php

namespace App\Services\HelpCenter;

use App\Models\HelpCenterRequest;
use App\Models\HelpCenterSpace;
use App\Models\User;

/**
 * What a queue screen boots with (docs/features/help-center.md, P21, P22).
 *
 * ONE builder for both scopes, because there is one screen. The Help Center's own Inbox shows a
 * view across every active Space; a Space's Inbox shows the same view narrowed to that Space
 * (P22). Building those in two controllers would be two answers to what a row looks like, what
 * order a queue is in and which pickers a row's menu offers — and the two would drift on the
 * first change to any of them.
 *
 * The scope changes three things and nothing else:
 *
 *   - the query gains a `where` on the Space;
 *   - grouping goes from by-Space to by-STATUS, because inside one Space there is one workflow
 *     to group by, and across several there is no shared vocabulary to group by at all;
 *   - the row menu's pickers can be the Space's own, rather than a map keyed by Space.
 */
class RequestQueue
{
    public function __construct(private readonly RequestViews $views) {}

    /**
     * @return array<string, mixed>
     */
    public function payload(string $view, User $user, ?HelpCenterSpace $space = null): array
    {
        return $this->payloadFor($view, $this->rows($view, $user, $space), $user, $space);
    }

    /**
     * A view's rows, extracted so the screen and its LIVE REFRESH cannot disagree (P67).
     *
     * The cross-Space queue updates itself the same way a Space's Inbox does: a socket event
     * says something moved, and the client asks for this. Running the same method the page was
     * rendered from is what keeps "longest waiting first", the view's own filter and the
     * per-Space `manageable` flag as one implementation rather than two.
     *
     * @return array<int, array<string, mixed>>
     */
    public function rows(string $view, User $user, ?HelpCenterSpace $space = null): array
    {
        $me = (int) $user->id;

        $query = $this->views->base();

        if ($space !== null) {
            $query->forSpace($space->id);
        }

        /*
         * Whether the row's ••• menu appears, decided PER SPACE.
         *
         * A Space's own queue can answer this once; a cross-Space list cannot — somebody may run
         * one Space and merely work in another. Memoised by Space id so a hundred rows from three
         * Spaces ask the policy three times, and the endpoints re-check regardless (hiding a
         * control is not enforcing it).
         */
        $manageable = $space !== null ? [(int) $space->id => $user->can('update', $space)] : [];

        return $this->views->apply($query, $view, $me)
            ->with(['assignee', 'status', 'space', 'tags'])
            // Longest-waiting first, then most recent: a queue is a queue, and the row that has
            // been owed an answer the longest is the one that should be read first.
            ->orderByRaw('waiting_since IS NULL, waiting_since ASC')
            ->orderByDesc('last_activity_at')
            ->orderByDesc('id')
            ->get()
            ->map(function (HelpCenterRequest $r) use ($user, $me, &$manageable) {
                $spaceId = (int) $r->help_center_space_id;
                $manageable[$spaceId] ??= $r->space !== null && $user->can('update', $r->space);

                return $r->toPayload() + [
                    'views' => $this->views->tags($r, $me),
                    // Which Space a row came from — the group header on the cross-Space list, and
                    // the key its menu's pickers are looked up by on either.
                    'space' => $r->space ? ['id' => $r->space->id, 'name' => $r->space->name] : null,
                    'manageable' => $manageable[$spaceId],
                ];
            })
            ->all();
    }

    /**
     * Every Space this person may hear about (P67).
     *
     * The channels the cross-Space queue subscribes to. Deliberately NOT derived from the rows
     * on screen, which is what `spaceOptions` does: a Space with nothing in the current view
     * would then have no channel, and the FIRST ticket to arrive there — the one most worth
     * knowing about — would be the one nobody was listening for.
     *
     * @return array<int, int>
     */
    public function liveSpaceIds(User $user): array
    {
        return HelpCenterSpace::query()->active()->get()
            ->filter(fn (HelpCenterSpace $s) => $user->can('view', $s))
            ->map(fn (HelpCenterSpace $s) => (int) $s->id)
            ->values()->all();
    }

    /**
     * Everything else the queue screen boots with.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function payloadFor(string $view, array $rows, User $user, ?HelpCenterSpace $space): array
    {
        $me = (int) $user->id;
        $item = RequestViews::item($view);

        return [
            'mode' => 'queue',
            'view' => $view,
            'title' => RequestViews::label($view),
            'rows' => $rows,
            'groupBy' => $space !== null ? 'status' : 'space',
            /*
             * The Space's workflow, for the group headers and the row menu — and NOT for a filter
             * bar, which the queue screens do not draw (the view is the URL). Empty across
             * Spaces, where two workflows are two vocabularies and a merged list would offer
             * "Open" twice meaning two different things.
             */
            'statuses' => $space !== null ? $this->statuses($space) : [],
            'spaceOptions' => $this->spaceOptions($rows),
            'priorities' => (array) config('help-center.priorities'),
            /*
             * The snooze conditions (P45), on the SCREEN rather than only in a Request's detail.
             *
             * The dialog opens from the row menu as well as from the drawer, and a dropdown that
             * has to wait for a fetch before it can show its two options is a dropdown that
             * flickers empty every time.
             */
            'snoozeConditions' => collect((array) config('help-center.snooze_conditions'))
                ->map(fn (array $c, string $key) => [
                    'value' => $key,
                    'label' => $c['label'],
                    'help' => $c['help'] ?? null,
                ])->values()->all(),
            // The rich-text editor's licence, as every other host of it passes it (P41).
            'editorLicense' => (string) config('projects.jodit_license'),
            'me' => $me,
            // The screen-level switch stays true; each row carries its own answer above.
            'canManage' => true,
            'emptyTitle' => 'Nothing in '.RequestViews::label($view),
            'emptyMessage' => (string) ($item['empty'] ?? 'No Requests are waiting for you.'),
            'endpointTemplates' => [
                // Nested under the Space the row belongs to, because that is where the permission
                // check lives — the client fills both ids from the row, at either scope.
                'update' => route('help-center.spaces.requests.update', [
                    'space' => '__SPACE__', 'request' => '__ID__',
                ]),
                // The drawer's contents, fetched on open (P32).
                'detail' => route('help-center.spaces.requests.detail', [
                    'space' => '__SPACE__', 'request' => '__ID__',
                ]),
                // Snooze and Unsnooze — one URL, two verbs (P45). Templated like the rest so a
                // row in the cross-Space queue can reach its own Space's endpoint.
                'snooze' => route('help-center.spaces.requests.snooze.store', [
                    'space' => '__SPACE__', 'request' => '__ID__',
                ]),
                // The Request's own page (P46) — Expand links here, Copy Ticket Link copies it.
                'page' => route('help-center.spaces.requests.page', [
                    'space' => '__SPACE__', 'request' => '__ID__',
                ]),
            ],
            'endpoints' => [
                'counts' => route('help-center.inbox.counts'),
                /*
                 * Where a live update sends this screen back for its rows (P67).
                 *
                 * NULL for the default queue, not the string `inbox`. The route's `{view}` is
                 * enumerated from `request_views`, which deliberately does NOT contain `inbox`
                 * — that is the bare URL — so passing it would build `/inbox/rows/inbox` and
                 * 404 on the one view most people are looking at.
                 */
                'rows' => route('help-center.inbox.rows', [
                    'view' => $view === RequestViews::INBOX ? null : $view,
                ]),
            ],
            /*
             * The Spaces to listen on (P67).
             *
             * Resolved for the SIGNED-IN user rather than from the rows, so a Space that is
             * currently empty in this view still has a channel — otherwise the first ticket to
             * arrive there would be the one nobody heard.
             */
            'liveSpaceIds' => $this->liveSpaceIds($user),
        ];
    }

    /**
     * The row menu's pickers, per Space (P21).
     *
     * A Request may only take a status from ITS OWN Space's workflow (P9) and an assignee from
     * its own Space's members, so a merged list would offer a row answers it cannot accept. Keyed
     * by Space even when there is only one, so the screen has one lookup rather than two paths.
     *
     * Only the Spaces actually ON SCREEN, in one query rather than per row.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    /** Public alias so the live-rows endpoint can send the pickers with the rows (P67). */
    public function spaceOptionsFor(array $rows): array
    {
        return $this->spaceOptions($rows);
    }

    private function spaceOptions(array $rows): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(fn (array $r) => $r['space']['id'] ?? null, $rows),
        )));

        if ($ids === []) {
            return [];
        }

        return HelpCenterSpace::query()
            ->whereIn('id', $ids)
            ->with(['statuses', 'members.user', 'tags'])
            ->get()
            ->mapWithKeys(fn (HelpCenterSpace $space) => [(string) $space->id => [
                'statuses' => $this->statuses($space),
                // The Space's own tag vocabulary, for the row's Tag chip (P28).
                'tags' => $space->tags->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])->values()->all(),
                'members' => $this->members($space),
            ]])
            ->all();
    }

    /**
     * Who the Assignee picker offers — and who it SHOWS but will not offer (P29).
     *
     * A Space member with no `user_id` has been invited and has not accepted, so there is no
     * account to assign work to. Filtering them out entirely is what this used to do, and it
     * produced the report that started P29: the Members grid shows four people, the picker
     * offers one, and nothing on screen explains the gap.
     *
     * They travel with `pending: true` instead. The picker draws them greyed with an "Invited"
     * badge — present, so the list matches the Members grid, and unclickable, so the rule is
     * still the rule. The endpoint refuses them regardless; this is not the enforcement.
     *
     * Accepted members first: the list's job is assigning, and the people who can be assigned
     * should not be interleaved with the people who cannot.
     *
     * @return array<int, array<string, mixed>>
     */
    private function members(HelpCenterSpace $space): array
    {
        return $space->members
            ->map(function ($m) {
                if ($m->user !== null) {
                    return [
                        'id' => $m->user->id,
                        'name' => $m->user->displayName(),
                        'initial' => mb_strtoupper(mb_substr((string) $m->user->displayName(), 0, 1)),
                        'avatar_url' => $m->user->avatar_url ?? null,
                        'pending' => false,
                    ];
                }

                return [
                    // No account, so no id to assign to — the picker keys off `pending` anyway.
                    'id' => null,
                    'name' => (string) $m->email,
                    'initial' => mb_strtoupper(mb_substr((string) $m->email, 0, 1)) ?: '?',
                    'avatar_url' => null,
                    'pending' => true,
                ];
            })
            ->sortBy('pending')
            ->values()
            ->all();
    }

    /** One Space's workflow, in workflow order. */
    private function statuses(HelpCenterSpace $space): array
    {
        return $space->statuses->sortBy('position')->values()
            ->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'color' => $s->color,
                'waiting_on' => $s->waiting_on,
            ])->all();
    }
}
