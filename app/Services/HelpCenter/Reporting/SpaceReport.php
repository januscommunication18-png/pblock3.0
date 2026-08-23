<?php

namespace App\Services\HelpCenter\Reporting;

use App\Models\HelpCenterMessage;
use App\Models\HelpCenterRequest;
use App\Models\HelpCenterSpace;
use App\Models\HelpCenterStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The Space Overview reporting dashboard (docs/features/help-center.md, P50).
 *
 * ONE service that answers every panel, because every panel is a different reading of the same
 * filtered set of Requests — and a dashboard whose KPI card and whose chart each ran their own
 * query is a dashboard whose two halves eventually disagree in front of a customer.
 *
 * ## How this reads the data, and what that costs
 *
 * FOUR queries, then arithmetic in PHP:
 *
 *   1. the period's Requests, narrow column set
 *   2. the current open Requests (for the current-state panels)
 *   3. the first agent reply per Request
 *   4. tags, and agent reply counts
 *
 * Not a dozen aggregate queries, and deliberately not `DATE_FORMAT`/`TIMESTAMPDIFF` grouping in
 * SQL: those are MySQL's spelling, and CLAUDE.md §6 asks this codebase to stay portable. Bucketing
 * in PHP is the portable way to say the same thing.
 *
 * The honest limit: this loads the period's rows into memory. At the scale this module runs at
 * today that is a few hundred rows and it is fine. The requirement's §23 — aggregated tables,
 * cached statistics, background aggregation — is the Phase 2 answer, and this class is written so
 * that swapping the four reads for pre-aggregated ones changes nothing above it.
 *
 * ## Period metrics vs current-state metrics
 *
 * The requirement is careful about the difference and so is this. A metric about what HAPPENED
 * (created, closed, first response) is bounded by the date range. A metric about what IS (open,
 * unassigned, workload, aging, longest waiting) ignores the range entirely — asking "how many are
 * open?" of last month is a question with no answer. Both honour the other filters.
 */
class SpaceReport
{
    public function __construct(private readonly HelpCenterSpace $space) {}

    /** @return array<string, mixed> */
    public function build(ReportFilters $filters): array
    {
        $statuses = $this->space->statuses->sortBy('position')->values();
        $closedIds = $statuses->filter(fn (HelpCenterStatus $s) => $s->isClosed())->pluck('id')->all();

        $period = $this->periodRows($filters);
        $current = $this->currentRows($filters);

        $firstReplies = $this->firstAgentReplies($period->pluck('id')->all());
        $tagCounts = $this->tagCounts($period->pluck('id')->all());

        return [
            'filters' => $filters->toPayload(),
            'kpis' => $this->kpis($period, $current, $firstReplies),
            'volume' => $this->volume($period, $filters),
            'statusBreakdown' => $this->statusBreakdown($period, $statuses),
            'priorityBreakdown' => $this->priorityBreakdown($period),
            'agents' => $this->agentPerformance($period, $current, $firstReplies),
            'workload' => $this->workload($current),
            'aging' => $this->aging($current),
            'longestWaiting' => $this->longestWaiting($current),
            'tags' => $tagCounts,
            /*
             * Two different empty states, and the difference matters (§19/§20).
             *
             * A Space with no tickets at all needs "your reports will appear here"; a Space with
             * tickets but nothing in this window needs "try a different range". Telling somebody
             * to widen their filters when they have never received an email would be nonsense,
             * and `$period` alone cannot tell the two apart — hence the second count.
             */
            'hasAnyTickets' => HelpCenterRequest::query()->forSpace($this->space->id)->exists(),
            'periodCount' => $period->count(),
            'closedStatusIds' => $closedIds,
        ];
    }

    /**
     * Requests CREATED in the window, with the filters applied.
     *
     * Only the columns the report reads. A dashboard that selected `*` would pull every body
     * preview in the Space through memory to count them.
     */
    private function periodRows(ReportFilters $filters): Collection
    {
        return $this->apply(
            HelpCenterRequest::query()
                ->forSpace($this->space->id)
                ->whereBetween('created_at', [$filters->from, $filters->to]),
            $filters,
        )->get($this->columns());
    }

    /**
     * Requests as they stand RIGHT NOW — open, not spam, not snoozed.
     *
     * No date bound, on purpose: see the class note. Snoozed is excluded because a snoozed ticket
     * is not in anybody's queue (P45), so counting it as workload would inflate the number the
     * report exists to make actionable.
     */
    private function currentRows(ReportFilters $filters): Collection
    {
        return $this->apply(
            HelpCenterRequest::query()
                ->forSpace($this->space->id)
                ->whereNull('closed_at')
                ->where('is_spam', false)
                ->notSnoozed(),
            $filters,
        )->get($this->columns());
    }

    private function columns(): array
    {
        return [
            'id', 'ticket_number', 'subject', 'created_at', 'closed_at', 'is_spam',
            'snoozed_until', 'assignee_id', 'help_center_status_id', 'priority',
            'waiting_since', 'last_activity_at', 'customer_email', 'customer_name',
        ];
    }

    /** The four dimension filters, applied identically to both reads. */
    private function apply($query, ReportFilters $filters)
    {
        if ($filters->assignees !== []) {
            $ids = $filters->assigneeIds();

            $query->where(function ($q) use ($ids, $filters) {
                if ($ids !== []) {
                    $q->whereIn('assignee_id', $ids);
                }

                // The unassigned sentinel is an OR, not a separate filter: "Priya or unassigned"
                // is one question, and an AND would make it unanswerable.
                if ($filters->wantsUnassigned()) {
                    $q->orWhereNull('assignee_id');
                }
            });
        }

        if ($filters->statuses !== []) {
            $query->whereIn('help_center_status_id', $filters->statuses);
        }

        if ($filters->priorities !== []) {
            $query->whereIn('priority', $filters->priorities);
        }

        if ($filters->tags !== []) {
            // A Request carries many tags, so this is "has ANY of these" — the reading every
            // tag filter in this application uses, and the one a person picking two tags means.
            $query->whereExists(function ($q) use ($filters) {
                $q->select(DB::raw(1))
                    ->from('help_center_request_tag')
                    ->whereColumn('help_center_request_tag.help_center_request_id', 'help_center_requests.id')
                    ->whereIn('help_center_request_tag.help_center_tag_id', $filters->tags);
            });
        }

        return $query;
    }

    /**
     * When each Request first got an answer from a person.
     *
     * OUTBOUND messages only, which in this module means an agent's reply and nothing else — the
     * acknowledgement (P27) sends mail without storing a message row, and internal notes and
     * updates are their own tables. That is what satisfies "customer replies, automated emails
     * and system updates must not count".
     *
     * @return array<int, CarbonImmutable> keyed by request id
     */
    private function firstAgentReplies(array $requestIds): array
    {
        if ($requestIds === []) {
            return [];
        }

        return HelpCenterMessage::query()
            ->whereIn('help_center_request_id', $requestIds)
            ->where('direction', HelpCenterMessage::DIRECTION_OUTBOUND)
            ->selectRaw('help_center_request_id, MIN(received_at) AS first_at')
            ->groupBy('help_center_request_id')
            ->pluck('first_at', 'help_center_request_id')
            ->map(fn ($v) => CarbonImmutable::parse($v))
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function tagCounts(array $requestIds): array
    {
        if ($requestIds === []) {
            return [];
        }

        $rows = DB::table('help_center_request_tag AS rt')
            ->join('help_center_tags AS t', 't.id', '=', 'rt.help_center_tag_id')
            ->whereIn('rt.help_center_request_id', $requestIds)
            ->selectRaw('t.id, t.name, COUNT(*) AS total')
            ->groupBy('t.id', 't.name')
            ->orderByDesc('total')
            ->get();

        // The percentage denominator is the number of TAGGED requests, not the total: a Space
        // where half the tickets carry no tag would otherwise show percentages summing to 50 and
        // look broken. Stated here because the alternative reading is defensible and this is the
        // one the numbers are built on.
        $total = max(1, (int) $rows->sum('total'));

        return $rows->map(fn ($r) => [
            'id' => (int) $r->id,
            'name' => $r->name,
            'total' => (int) $r->total,
            'percent' => round(((int) $r->total / $total) * 100, 1),
        ])->all();
    }

    /** @return array<string, mixed> */
    private function kpis(Collection $period, Collection $current, array $firstReplies): array
    {
        $closed = $period->filter(fn ($r) => $r->closed_at !== null);

        $responseMinutes = $period
            ->filter(fn ($r) => isset($firstReplies[$r->id]))
            ->map(fn ($r) => $r->created_at->diffInMinutes($firstReplies[$r->id]))
            ->filter(fn ($m) => $m >= 0);

        $resolutionMinutes = $closed->map(fn ($r) => $r->created_at->diffInMinutes($r->closed_at))
            ->filter(fn ($m) => $m >= 0);

        return [
            'total' => $period->count(),
            // Current state, as the requirement says of both of these.
            'open' => $current->count(),
            'unassigned' => $current->filter(fn ($r) => $r->assignee_id === null)->count(),
            'closed' => $closed->count(),
            /*
             * NULL, not 0, when nothing has been answered yet.
             *
             * "0 min average first response" reads as instant service; it actually means no
             * ticket in this window has been replied to. The screen prints an em dash.
             */
            'firstResponse' => $responseMinutes->isEmpty() ? null : (int) round($responseMinutes->avg()),
            'firstResponseCount' => $responseMinutes->count(),
            'resolution' => $resolutionMinutes->isEmpty() ? null : (int) round($resolutionMinutes->avg()),
            'resolutionCount' => $resolutionMinutes->count(),
        ];
    }

    /**
     * Created vs closed over time (§5).
     *
     * Created is bucketed on `created_at`, closed on `closed_at` — so a ticket opened on Monday
     * and closed on Friday appears in both series, in different buckets. That is the comparison
     * the chart is for: a week where the closed line sits under the created line is a week the
     * queue grew.
     *
     * @return array<string, mixed>
     */
    private function volume(Collection $period, ReportFilters $filters): array
    {
        $grouping = $filters->grouping();
        $buckets = $this->buckets($filters->from, $filters->to, $grouping);

        $created = array_fill_keys(array_keys($buckets), 0);
        $closed = array_fill_keys(array_keys($buckets), 0);

        foreach ($period as $row) {
            $key = $this->bucketKey($row->created_at, $grouping);

            if (array_key_exists($key, $created)) {
                $created[$key]++;
            }

            if ($row->closed_at !== null) {
                $closedKey = $this->bucketKey($row->closed_at, $grouping);

                if (array_key_exists($closedKey, $closed)) {
                    $closed[$closedKey]++;
                }
            }
        }

        return [
            'grouping' => $grouping,
            'points' => collect($buckets)->map(fn (string $label, string $key) => [
                'key' => $key,
                'label' => $label,
                'created' => $created[$key],
                'closed' => $closed[$key],
            ])->values()->all(),
        ];
    }

    /**
     * Every bucket in the window, INCLUDING the empty ones.
     *
     * Built from the range rather than from the data, so a quiet Tuesday is a gap in the chart
     * instead of vanishing and making Monday sit next to Wednesday as though they were adjacent.
     *
     * @return array<string, string> key => label
     */
    private function buckets(CarbonImmutable $from, CarbonImmutable $to, string $grouping): array
    {
        $out = [];
        $cursor = match ($grouping) {
            'hour' => $from->startOfHour(),
            'week' => $from->startOfWeek(),
            'month' => $from->startOfMonth(),
            default => $from->startOfDay(),
        };

        // A hard cap: a custom range of ten years grouped by hour is 87,600 buckets and a browser
        // that stops responding. The chart is unreadable long before this, so it is a guard
        // rather than a limit anybody will meet by accident.
        $guard = 0;

        while ($cursor->lessThanOrEqualTo($to) && $guard++ < 1000) {
            $out[$this->bucketKey($cursor, $grouping)] = match ($grouping) {
                'hour' => $cursor->format('M j, ga'),
                'week' => 'w/c '.$cursor->format('M j'),
                'month' => $cursor->format('M Y'),
                default => $cursor->format('M j'),
            };

            $cursor = match ($grouping) {
                'hour' => $cursor->addHour(),
                'week' => $cursor->addWeek(),
                'month' => $cursor->addMonth(),
                default => $cursor->addDay(),
            };
        }

        return $out;
    }

    private function bucketKey(CarbonImmutable|\Carbon\Carbon $at, string $grouping): string
    {
        $at = CarbonImmutable::parse($at);

        return match ($grouping) {
            'hour' => $at->format('Y-m-d H'),
            'week' => $at->startOfWeek()->format('Y-m-d'),
            'month' => $at->format('Y-m'),
            default => $at->format('Y-m-d'),
        };
    }

    /**
     * Tickets by status (§6) — from the SPACE'S OWN workflow, never a fixed list.
     *
     * @return array<int, array<string, mixed>>
     */
    private function statusBreakdown(Collection $period, Collection $statuses): array
    {
        $total = max(1, $period->count());
        $counts = $period->groupBy('help_center_status_id')->map->count();

        $rows = $statuses->map(fn (HelpCenterStatus $s) => [
            'id' => $s->id,
            'name' => $s->name,
            'color' => $s->color,
            'total' => (int) ($counts[$s->id] ?? 0),
            'percent' => round(((int) ($counts[$s->id] ?? 0) / $total) * 100, 1),
        ]);

        // Requests with no status at all — possible for a Space whose workflow was built after
        // its first email arrived. Shown rather than dropped, because a breakdown whose parts do
        // not add up to the total is a breakdown people stop trusting.
        $none = $period->filter(fn ($r) => $r->help_center_status_id === null)->count();

        if ($none > 0) {
            $rows->push([
                'id' => null,
                'name' => 'No status',
                'color' => '#9ca3af',
                'total' => $none,
                'percent' => round(($none / $total) * 100, 1),
            ]);
        }

        return $rows->values()->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function priorityBreakdown(Collection $period): array
    {
        $total = max(1, $period->count());
        $counts = $period->groupBy('priority')->map->count();

        return collect((array) config('help-center.priorities'))
            ->map(fn (array $meta, string $key) => [
                'key' => $key,
                'label' => $meta['label'] ?? $key,
                'color' => $meta['color'] ?? '#9ca3af',
                'total' => (int) ($counts[$key] ?? 0),
                'percent' => round(((int) ($counts[$key] ?? 0) / $total) * 100, 1),
            ])
            ->values()
            ->all();
    }

    /**
     * Per-agent performance (§8).
     *
     * "Closed" and "replied" are both attributed by ASSIGNEE, and that is a real approximation
     * worth stating: this module records who a Request is assigned to, not who pressed close. A
     * ticket closed by a colleague covering someone's shift counts to the person it was assigned
     * to. The activity log has the actor and could answer it exactly — that is a Phase 2 change,
     * and pretending otherwise here would be a number that looks precise and is not.
     *
     * @return array<int, array<string, mixed>>
     */
    private function agentPerformance(Collection $period, Collection $current, array $firstReplies): array
    {
        $members = $this->space->members->filter(fn ($m) => $m->user !== null);

        $repliedCounts = $this->agentReplyCounts($period->pluck('id')->all());

        return $members->map(function ($member) use ($period, $current, $firstReplies, $repliedCounts) {
            $user = $member->user;
            $mine = $period->filter(fn ($r) => (int) $r->assignee_id === (int) $user->id);
            $closed = $mine->filter(fn ($r) => $r->closed_at !== null);

            $response = $mine->filter(fn ($r) => isset($firstReplies[$r->id]))
                ->map(fn ($r) => $r->created_at->diffInMinutes($firstReplies[$r->id]))
                ->filter(fn ($m) => $m >= 0);

            $resolution = $closed->map(fn ($r) => $r->created_at->diffInMinutes($r->closed_at))
                ->filter(fn ($m) => $m >= 0);

            return [
                'id' => $user->id,
                'name' => $user->displayName(),
                'initial' => mb_strtoupper(mb_substr((string) $user->displayName(), 0, 1)),
                'avatar_url' => $user->avatar_url ?? null,
                'assigned' => $mine->count(),
                'replied' => (int) ($repliedCounts[$user->id] ?? 0),
                'closed' => $closed->count(),
                'open' => $current->filter(fn ($r) => (int) $r->assignee_id === (int) $user->id)->count(),
                'firstResponse' => $response->isEmpty() ? null : (int) round($response->avg()),
                'resolution' => $resolution->isEmpty() ? null : (int) round($resolution->avg()),
            ];
        })
            ->sortByDesc('assigned')
            ->values()
            ->all();
    }

    /**
     * How many of the period's Requests each agent actually replied to.
     *
     * From the MESSAGES, not from the assignment — this is the one agent metric that is measured
     * rather than attributed, and the only one that survives a ticket being reassigned.
     *
     * Matched on `from_email`, because `help_center_messages` has no author column: an outbound
     * message stores the address the agent sent AS, which `RequestController::reply()` sets to
     * their own. Lower-cased on both sides, since an email address is not case-sensitive in its
     * domain and people's stored casing is inconsistent.
     *
     * An agent whose account email later changes stops matching their older replies. Recording
     * an `author_id` on the message would fix that permanently and is the right change; it is
     * not this one, and a reader deserves to know which of these numbers is exact.
     *
     * @return array<int, int> keyed by user id
     */
    private function agentReplyCounts(array $requestIds): array
    {
        if ($requestIds === []) {
            return [];
        }

        /*
         * `author_email`, falling back to `from_email` (P62).
         *
         * Since P62 the From is the SPACE'S address, so matching on it would credit every reply
         * in the Space to nobody. `author_email` is the agent; `COALESCE` covers rows written
         * before that column existed, which the migration backfilled but which a restored older
         * dump might not have.
         */
        $byEmail = HelpCenterMessage::query()
            ->whereIn('help_center_request_id', $requestIds)
            ->where('direction', HelpCenterMessage::DIRECTION_OUTBOUND)
            ->selectRaw('LOWER(COALESCE(author_email, from_email)) AS sender, COUNT(DISTINCT help_center_request_id) AS total')
            ->whereRaw('COALESCE(author_email, from_email) IS NOT NULL')
            ->groupBy('sender')
            ->pluck('total', 'sender')
            ->all();

        $out = [];

        foreach ($this->space->members as $member) {
            $user = $member->user;

            if ($user === null) {
                continue;
            }

            $key = mb_strtolower(trim((string) $user->email));

            if ($key !== '' && isset($byEmail[$key])) {
                $out[(int) $user->id] = (int) $byEmail[$key];
            }
        }

        return $out;
    }

    /**
     * Who is carrying what right now (§9) — current state, never the date range.
     *
     * @return array<int, array<string, mixed>>
     */
    private function workload(Collection $current): array
    {
        $rows = $this->space->members
            ->filter(fn ($m) => $m->user !== null)
            ->map(fn ($m) => [
                'id' => $m->user->id,
                'name' => $m->user->displayName(),
                'initial' => mb_strtoupper(mb_substr((string) $m->user->displayName(), 0, 1)),
                'avatar_url' => $m->user->avatar_url ?? null,
                'total' => $current->filter(fn ($r) => (int) $r->assignee_id === (int) $m->user->id)->count(),
            ])
            ->values();

        $unassigned = $current->filter(fn ($r) => $r->assignee_id === null)->count();

        // Unassigned is a row in this table, not a separate note: the requirement asks the report
        // to surface "unassigned workload" alongside overloaded and idle agents, and a number
        // sitting outside the comparison is a number nobody compares.
        $rows->push([
            'id' => 0,
            'name' => 'Unassigned',
            'initial' => '?',
            'avatar_url' => null,
            'total' => $unassigned,
        ]);

        return $rows->sortByDesc('total')->values()->all();
    }

    /**
     * How long the unresolved have been unresolved (§11).
     *
     * Closed and spam are excluded by `currentRows()`, which is where that rule already lives.
     * Measured from CREATION, which is what "how long have they remained open" asks — not from
     * the waiting clock, which resets each time responsibility changes hands.
     *
     * @return array<int, array<string, mixed>>
     */
    private function aging(Collection $current): array
    {
        $now = CarbonImmutable::now();

        $buckets = [
            ['key' => 'lt1h', 'label' => 'Less than 1 hour', 'max' => 60],
            ['key' => '1_4h', 'label' => '1–4 hours', 'max' => 240],
            ['key' => '4_24h', 'label' => '4–24 hours', 'max' => 1440],
            ['key' => '1_3d', 'label' => '1–3 days', 'max' => 4320],
            ['key' => '3_7d', 'label' => '3–7 days', 'max' => 10080],
            ['key' => '7d', 'label' => '7+ days', 'max' => null],
        ];

        $out = [];

        foreach ($buckets as $i => $bucket) {
            $floor = $i === 0 ? 0 : $buckets[$i - 1]['max'];

            $count = $current->filter(function ($r) use ($now, $floor, $bucket) {
                $minutes = CarbonImmutable::parse($r->created_at)->diffInMinutes($now);

                return $minutes >= $floor && ($bucket['max'] === null || $minutes < $bucket['max']);
            })->count();

            $out[] = ['key' => $bucket['key'], 'label' => $bucket['label'], 'total' => $count];
        }

        return $out;
    }

    /**
     * The tickets that have been waiting longest (§12).
     *
     * Ordered by `waiting_since` ascending — oldest clock first, which is the requirement's
     * "longest waiting → shortest". Requests with no clock running sort last: nobody is waiting
     * on them, so they are not what this list is for.
     *
     * @return array<int, array<string, mixed>>
     */
    private function longestWaiting(Collection $current): array
    {
        $now = CarbonImmutable::now();
        $statuses = $this->space->statuses->keyBy('id');
        $members = $this->space->members->filter(fn ($m) => $m->user !== null)->keyBy('user_id');
        $priorities = (array) config('help-center.priorities');

        return $current
            ->filter(fn ($r) => $r->waiting_since !== null)
            ->sortBy(fn ($r) => CarbonImmutable::parse($r->waiting_since)->getTimestamp())
            ->take(10)
            ->map(function ($r) use ($now, $statuses, $members, $priorities) {
                $since = CarbonImmutable::parse($r->waiting_since);
                $status = $statuses[$r->help_center_status_id] ?? null;
                $assignee = $r->assignee_id ? ($members[$r->assignee_id] ?? null) : null;

                return [
                    'id' => $r->id,
                    'identifier' => '#'.str_pad((string) $r->ticket_number, 6, '0', STR_PAD_LEFT),
                    'subject' => $r->subject ?: '(no subject)',
                    'customer' => $r->customer_name ?: $r->customer_email,
                    'assignee' => $assignee?->user?->displayName(),
                    'status' => $status?->name,
                    'status_color' => $status?->color,
                    'priority' => $priorities[$r->priority]['label'] ?? $r->priority,
                    'waiting_minutes' => $since->diffInMinutes($now),
                    'waiting_since' => $since->format('M j, Y \a\t g:i A'),
                    'last_activity' => $r->last_activity_at
                        ? CarbonImmutable::parse($r->last_activity_at)->diffForHumans()
                        : null,
                ];
            })
            ->values()
            ->all();
    }
}
