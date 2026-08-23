<?php

namespace App\Services\HelpCenter\Reporting;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Throwable;

/**
 * What the Space Overview dashboard is being asked about (docs/features/help-center.md, P50).
 *
 * A value object rather than an array, because these seven things travel together through the
 * report, into the drill-down URLs and back out of the address bar — and a bare array is how
 * "assignee" comes to mean an id in one place and a name in another.
 *
 * Everything is read from the REQUEST, so a dashboard is a URL: it can be bookmarked, sent to a
 * colleague and reloaded without losing what it was showing. That is also what makes the
 * drill-downs work — a filtered ticket list is these same filters pointed at the Inbox.
 */
class ReportFilters
{
    /** The presets the requirement asks for, in the order the picker shows them. */
    public const RANGES = [
        'today' => 'Today',
        'yesterday' => 'Yesterday',
        'last_7' => 'Last 7 Days',
        'last_30' => 'Last 30 Days',
        'this_month' => 'This Month',
        'last_month' => 'Last Month',
        'custom' => 'Custom Range',
    ];

    /** How the volume chart buckets time. `auto` picks from the range — see `grouping()`. */
    public const GROUPINGS = ['auto', 'hour', 'day', 'week', 'month'];

    public function __construct(
        public readonly string $range,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        /** @var array<int, int> user ids; the sentinel 0 means "unassigned" */
        public readonly array $assignees,
        /** @var array<int, int> */
        public readonly array $statuses,
        /** @var array<int, string> */
        public readonly array $priorities,
        /** @var array<int, int> */
        public readonly array $tags,
        public readonly string $group,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $range = (string) $request->query('range', 'last_30');

        if (! array_key_exists($range, self::RANGES)) {
            $range = 'last_30';
        }

        [$from, $to] = self::resolveRange($range, $request);

        $group = (string) $request->query('group', 'auto');

        return new self(
            range: $range,
            from: $from,
            to: $to,
            assignees: self::ints($request->query('assignee')),
            statuses: self::ints($request->query('status')),
            priorities: array_values(array_filter(
                self::strings($request->query('priority')),
                fn (string $p) => array_key_exists($p, (array) config('help-center.priorities')),
            )),
            tags: self::ints($request->query('tag')),
            group: in_array($group, self::GROUPINGS, true) ? $group : 'auto',
        );
    }

    /**
     * The window, as an inclusive pair of instants.
     *
     * `startOfDay`/`endOfDay` throughout: somebody picking "Last 7 Days" means seven whole days,
     * not seven times twenty-four hours ending at whatever o'clock they happened to look. The
     * off-by-one that produces is the kind nobody notices until a daily total disagrees with
     * itself either side of lunch.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private static function resolveRange(string $range, Request $request): array
    {
        $now = CarbonImmutable::now();

        return match ($range) {
            'today' => [$now->startOfDay(), $now->endOfDay()],
            'yesterday' => [$now->subDay()->startOfDay(), $now->subDay()->endOfDay()],
            // Six days back PLUS today: "Last 7 Days" showing eight would be wrong, and one that
            // excluded today would be a report nobody can use before midnight.
            'last_7' => [$now->subDays(6)->startOfDay(), $now->endOfDay()],
            'last_30' => [$now->subDays(29)->startOfDay(), $now->endOfDay()],
            'this_month' => [$now->startOfMonth(), $now->endOfDay()],
            'last_month' => [$now->subMonth()->startOfMonth(), $now->subMonth()->endOfMonth()],
            'custom' => self::customRange($request, $now),
            default => [$now->subDays(29)->startOfDay(), $now->endOfDay()],
        };
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private static function customRange(Request $request, CarbonImmutable $now): array
    {
        $from = self::parse($request->query('from')) ?? $now->subDays(29);
        $to = self::parse($request->query('to')) ?? $now;

        // Reversed dates are a slip, not an error worth a 422: a range from Friday to Monday is
        // plainly Monday to Friday, and refusing it teaches nobody anything.
        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        return [$from->startOfDay(), $to->endOfDay()];
    }

    private static function parse(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * How the volume chart buckets time when the filter says `auto`.
     *
     * The requirement asks the system to choose sensibly and still let the user override. The
     * thresholds are about how many bars a chart carries before it stops being readable: a day
     * of hours is 24, a month of days is 30, a year of weeks is 52.
     */
    public function grouping(): string
    {
        if ($this->group !== 'auto') {
            return $this->group;
        }

        $days = $this->from->diffInDays($this->to) + 1;

        return match (true) {
            $days <= 2 => 'hour',
            $days <= 45 => 'day',
            $days <= 365 => 'week',
            default => 'month',
        };
    }

    public function hasFilters(): bool
    {
        return $this->assignees !== [] || $this->statuses !== []
            || $this->priorities !== [] || $this->tags !== [];
    }

    /** "Unassigned" travels as the id 0, because a null cannot survive a query string. */
    public function wantsUnassigned(): bool
    {
        return in_array(0, $this->assignees, true);
    }

    /** @return array<int, int> the real user ids, without the unassigned sentinel */
    public function assigneeIds(): array
    {
        return array_values(array_filter($this->assignees, fn (int $id) => $id > 0));
    }

    /** @return array<string, mixed> the shape the screen reads back, so the UI matches the URL */
    public function toPayload(): array
    {
        return [
            'range' => $this->range,
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'assignee' => $this->assignees,
            'status' => $this->statuses,
            'priority' => $this->priorities,
            'tag' => $this->tags,
            'group' => $this->group,
            'grouping' => $this->grouping(),
            'has_filters' => $this->hasFilters(),
        ];
    }

    /** @return array<int, int> */
    private static function ints(mixed $value): array
    {
        return array_values(array_unique(array_map(
            'intval',
            array_filter(self::strings($value), fn (string $v) => is_numeric($v)),
        )));
    }

    /** @return array<int, string> */
    private static function strings(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        // Accepts `?tag[]=1&tag[]=2` and `?tag=1,2` alike: the first is what the screen sends,
        // the second is what somebody hand-editing the address bar will write.
        $items = is_array($value) ? $value : explode(',', (string) $value);

        return array_values(array_filter(
            array_map(fn ($v) => trim((string) $v), $items),
            fn (string $v) => $v !== '',
        ));
    }
}
