<?php

namespace App\Services\HelpCenter;

use App\Models\HelpCenterRequest;
use App\Models\HelpCenterSpace;
use Illuminate\Database\Eloquent\Builder;

/**
 * What a Request view MEANS (docs/features/help-center.md, P21).
 *
 * Unassigned, Mine, Draft, Assigned, Closed and Spam are asked three different questions in
 * three different places — "which rows are in this view?" (the queue screen), "how many are
 * there?" (the navigation's counts) and "does this row still belong here after somebody changed
 * it?" (the screen, after a PATCH). Before this class the first two lived on the model as
 * scopes and the third was a hand-written list in `SpaceController::inboxPayload()` that
 * already disagreed with them: the scopes exclude closed Requests from Unassigned and Mine, the
 * tags did not, so a closed Request was counted nowhere and tagged "mine".
 *
 * One definition, three readings of it: `apply()` filters, `counts()` counts, `tags()` decides
 * membership for a single loaded row. Change what "Mine" means and all three change together.
 *
 * The views are read at TWO scopes (P22). Unscoped they span every active Space, which is the
 * top-level bar; narrowed to one Space they are that Space's own navigation. Same definitions,
 * one `where` between them. Archived Spaces are excluded from both — archiving is a visibility
 * switch (P3 §6), and a count that includes rows no list will show is a number nobody can act
 * on.
 */
class RequestViews
{
    /**
     * The Inbox itself — every Request still in play.
     *
     * Not one of the views, and deliberately not a seventh config entry: it is the queue with no
     * filter on it, which is what the bare `/help-center/inbox` URL means.
     */
    public const INBOX = 'inbox';

    /** No Request is ever a draft yet — there is no reply composer to save one from. */
    public const DRAFTS = 'drafts';

    /**
     * Every view, as configured.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return (array) config('help-center.request_views');
    }

    /** The keys a URL may carry — the views, plus the unfiltered Inbox. */
    public static function keys(): array
    {
        return array_merge([self::INBOX], array_keys(self::all()));
    }

    /** @return array<string, mixed>|null */
    public static function item(string $view): ?array
    {
        return self::all()[$view] ?? null;
    }

    /** The label a screen or a nav row shows for a view. */
    public static function label(string $view): string
    {
        return $view === self::INBOX ? 'Inbox' : (string) (self::item($view)['label'] ?? $view);
    }

    /**
     * Every Request the queue may show — the base every view narrows.
     *
     * Tenant isolation is the model's own global scope; this adds the one rule on top of it that
     * every reading shares.
     */
    public function base(): Builder
    {
        return HelpCenterRequest::query()
            ->whereIn('help_center_space_id', HelpCenterSpace::query()->active()->select('id'));
    }

    /**
     * Narrow a query to one view.
     *
     * `$userId` is who "Mine" means. It is passed rather than read from the session so the
     * counts and the rows on one page cannot be computed for two different people.
     */
    public function apply(Builder $query, string $view, int $userId): Builder
    {
        return match ($view) {
            /*
             * "All active/open conversations" — the working queue, so neither closed, nor spam,
             * nor SNOOZED (P45).
             *
             * Snoozing is the requirement's "temporarily remove a ticket from the agent's active
             * working queue", and a queue that still shows what was removed from it has not
             * removed anything. The same exclusion runs on the three assignment views below,
             * through the model's scopes, so there is one answer to "is this in play?".
             */
            self::INBOX => $query->whereNull('closed_at')->where('is_spam', false)->notSnoozed(),
            'unassigned' => $query->unassigned()->notSnoozed(),
            'mine' => $query->assignedTo($userId)->notSnoozed(),
            'assigned' => $query->assigned()->notSnoozed(),
            'snoozed' => $query->snoozed(),
            /*
             * Closed and Spam are NOT filtered on snooze, and that is deliberate.
             *
             * They are archives — "everything that ended up here" — rather than working queues,
             * and hiding a row from an archive because of a timer is how a ticket becomes
             * impossible to find. In practice closing or spamming a snoozed Request ends its
             * snooze anyway (see SnoozeManager), so this is a belt on top of braces.
             */
            'closed' => $query->closed(),
            'spam' => $query->spam(),
            /*
             * Empty, on purpose and not by accident.
             *
             * Drafts are replies somebody saved without sending, and there is no composer to
             * save one from yet. `whereRaw('1 = 0')` is the honest answer — the alternative is
             * a view that silently shows the whole queue the day somebody adds a filter and
             * forgets this branch.
             */
            self::DRAFTS => $query->whereRaw('1 = 0'),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /**
     * Which views a single row belongs to.
     *
     * The update endpoint answers with one Request, not with the whole queue, so the screen has
     * to decide on its own whether that row still belongs where it is sitting. These are the
     * same predicates `apply()` uses, expressed against a loaded model.
     *
     * @return array<int, string>
     */
    public function tags(HelpCenterRequest $request, int $userId): array
    {
        $open = ! $request->isClosed() && ! $request->is_spam;

        // Snoozing takes a row OUT of the active views and puts it in exactly one other (P45),
        // so `$active` is what the three assignment views and the Inbox now test.
        $snoozed = $request->isSnoozed();
        $active = $open && ! $snoozed;

        return array_values(array_filter([
            $active && $request->assignee_id === null ? 'unassigned' : null,
            $active && (int) $request->assignee_id === $userId ? 'mine' : null,
            $active && $request->assignee_id !== null ? 'assigned' : null,
            $snoozed ? 'snoozed' : null,
            $request->isClosed() && ! $request->is_spam ? 'closed' : null,
            $request->is_spam ? 'spam' : null,
            // The Inbox is a view a row can leave too — closing or snoozing one takes it off.
            $active ? self::INBOX : null,
        ]));
    }

    /**
     * How many Requests each counted view holds, for the navigation (P21).
     *
     * ONE query with conditional sums rather than a count per view: this runs on every Help
     * Center page, because the navigation is on every Help Center page, and five round trips
     * for five numbers on one bar is four too many.
     *
     * `$spaceId` narrows it to one Space, which is what the Space's own navigation shows (P22).
     * Null counts every active Space, which is what the top-level bar shows.
     *
     * Only the counted views are computed. Drafts is always zero and Closed is an archive
     * rather than a workload — neither draws a number, so neither is worth a column.
     *
     * @return array<string, int>
     */
    public function counts(int $userId, ?int $spaceId = null): array
    {
        $query = $this->base()->toBase();

        if ($spaceId !== null) {
            $query->where('help_center_space_id', $spaceId);
        }

        [$sql, $bindings] = self::countColumns($userId);

        $row = $query->selectRaw($sql, $bindings)->first();

        return self::readCounts($row);
    }

    /**
     * The same three numbers, for EVERY Space at once.
     *
     * The sidebar draws a Space's own counts under each Space (P22), so asking per Space would
     * be one query per Space on every Help Center page — the N+1 that a tree of navigation
     * quietly becomes. One GROUP BY answers all of them.
     *
     * @return array<int, array<string, int>>
     */
    public function countsBySpace(int $userId): array
    {
        [$sql, $bindings] = self::countColumns($userId);

        $rows = $this->base()->toBase()
            ->selectRaw('help_center_space_id AS space_id, '.$sql, $bindings)
            ->groupBy('help_center_space_id')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row->space_id] = self::readCounts($row);
        }

        return $out;
    }

    /**
     * The conditional sums and their bindings, written once and RETURNED TOGETHER.
     *
     * This used to be a `const` string with the bindings written out by hand at each of the two
     * call sites, and its own comment warned that anyone changing the columns had to change both
     * lists to match. P45 changed the columns, which is exactly the edit that warning was about.
     * A method that hands back both makes the pairing impossible to get wrong instead of merely
     * documenting how to get it right.
     *
     * `CASE WHEN` is ANSI SQL and every literal is bound rather than inlined, so this stays
     * portable (CLAUDE.md §6).
     *
     * `$now` is bound rather than written as `NOW()` for the same reason the model's scopes use
     * it: snoozed means `snoozed_until > now`, so a Request whose time has passed counts as
     * active again with no sweep having run (P45).
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    private static function countColumns(int $userId): array
    {
        $active = 'closed_at IS NULL AND is_spam = ? AND (snoozed_until IS NULL OR snoozed_until <= ?)';
        $now = now();

        $sql = 'SUM(CASE WHEN assignee_id IS NULL AND '.$active.' THEN 1 ELSE 0 END) AS unassigned,'
            .'SUM(CASE WHEN assignee_id = ? AND '.$active.' THEN 1 ELSE 0 END) AS mine,'
            .'SUM(CASE WHEN assignee_id IS NOT NULL AND '.$active.' THEN 1 ELSE 0 END) AS assigned,'
            .'SUM(CASE WHEN snoozed_until > ? AND closed_at IS NULL AND is_spam = ? THEN 1 ELSE 0 END) AS snoozed';

        return [$sql, [
            false, $now,             // unassigned
            $userId, false, $now,    // mine
            false, $now,             // assigned
            $now, false,             // snoozed
        ]];
    }

    /** @return array<string, int> */
    private static function readCounts(?object $row): array
    {
        return [
            'unassigned' => (int) ($row->unassigned ?? 0),
            'mine' => (int) ($row->mine ?? 0),
            'assigned' => (int) ($row->assigned ?? 0),
            'snoozed' => (int) ($row->snoozed ?? 0),
        ];
    }
}
