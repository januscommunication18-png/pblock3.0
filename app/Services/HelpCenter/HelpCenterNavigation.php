<?php

namespace App\Services\HelpCenter;

use App\Models\HelpCenterInbox;
use App\Models\HelpCenterSpace;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * What the Help Center sidebar draws (docs/features/help-center.md §13, §15, §16).
 *
 * Built here rather than in each controller for the reason WikiNavigation exists: the partial
 * rides along with every Help Center screen, so the list it shows must be assembled in ONE
 * place instead of in each of the five controllers that happen to render it.
 *
 * The counts (§17) that were "deliberately absent — they count Requests, and there are none
 * yet" now exist: Requests arrived with P9, and P21 put the queues they belong to in this
 * navigation. `RequestViews` says what each one counts; this class only places it.
 */
class HelpCenterNavigation
{
    public function __construct(private readonly RequestViews $views) {}

    /**
     * Every Space's counts, computed once per request.
     *
     * The sidebar draws the tree AND the current Space's tab bar, so `views()` is called once
     * per Space plus once more. Memoised here rather than passed down through four call sites:
     * they all want the same numbers for the same person on the same page.
     *
     * @var array<int, array<string, int>>|null
     */
    private ?array $spaceCounts = null;

    /**
     * The Help Center's top-level queues (P21) — Inbox, then the views that appear in the nav.
     *
     * Overview and Spaces are not here: they are single links this list knows nothing about,
     * and putting them in it would mean the partial could no longer say what order the bar is
     * in. What this owns is the middle — the queues, their URLs, their counts and which one is
     * lit — because all four are answers only the server has.
     *
     * ONE counts query for the whole bar, not one per row.
     *
     * @return array<int, array<string, mixed>>
     */
    public function queue(?Request $request = null): array
    {
        $user = Auth::user();

        if ($user === null) {
            return [];
        }

        $counts = $this->views->counts((int) $user->id);
        $current = self::currentView($request);

        $rows = [[
            'key' => RequestViews::INBOX,
            'label' => RequestViews::label(RequestViews::INBOX),
            'icon' => 'inbox',
            'url' => route('help-center.inbox'),
            /*
             * No number beside Inbox, although one could be computed.
             *
             * It would be the total of the three below it, so the bar would carry the same
             * Requests twice and invite the reader to add them up. The counts that mean
             * something are the ones that say what is unattended.
             */
            'count' => null,
            'active' => $current === RequestViews::INBOX,
        ]];

        foreach (RequestViews::all() as $key => $view) {
            if (! ($view['nav'] ?? false)) {
                continue;
            }

            $count = ($view['counted'] ?? false) ? (int) ($counts[$key] ?? 0) : 0;

            $rows[] = [
                'key' => $key,
                'label' => (string) $view['label'],
                'icon' => (string) ($view['icon'] ?? 'inbox'),
                'url' => route('help-center.inbox.view', ['view' => $key]),
                // NULL, not 0 — "do not show a count when the value is 0" (P21), and a zero is
                // a thing a template has to remember not to print while null is simply absent.
                'count' => $count > 0 ? $count : null,
                'active' => $current === $key,
            ];
        }

        return $rows;
    }

    /**
     * The Company & Customer nav item (P75 §1), or null when no Space runs the feature.
     *
     * It sits after Spam — the last of the queues — and before Spaces, which is where the
     * requirement puts it.
     *
     * Shown when ANY Space has the master switch on (HC-D57). The item is in the TOP-LEVEL bar,
     * which is not per-Space, and the requirement's "only when enabled for that Space" is
     * satisfied by the page itself: it lists only the Spaces that have it on, and 404s when
     * none do.
     *
     * @return array<string, mixed>|null
     */
    public function companyCustomer(?Request $request = null): ?array
    {
        $enabled = HelpCenterSpace::query()
            ->active()
            ->with('settings')
            ->get()
            ->contains(fn (HelpCenterSpace $space) => $space->featureEnabled('company'));

        if (! $enabled) {
            return null;
        }

        $name = (string) $request?->route()?->getName();

        return [
            'label' => 'Company & Customer',
            'icon' => 'users',
            'url' => route('help-center.company-customer'),
            'active' => str_starts_with($name, 'help-center.company-customer'),
        ];
    }

    /**
     * Which queue the current URL is standing on, if any.
     *
     * Read from the route rather than from a `$section` the controller sets, for the reason the
     * Settings tab needed the same treatment: a screen that forgets to say where it is leaves
     * the navigation quietly claiming somewhere else.
     */
    public static function currentView(?Request $request): ?string
    {
        $name = (string) $request?->route()?->getName();

        if ($name === 'help-center.inbox') {
            return RequestViews::INBOX;
        }

        return $name === 'help-center.inbox.view' ? (string) $request?->route('view') : null;
    }

    /**
     * The Spaces tree, each Space with its six system views.
     *
     * @return array<int, array<string, mixed>>
     */
    public function spaces(Request $request): array
    {
        $spaces = HelpCenterSpace::query()
            ->active()
            ->with('lead')
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        return $spaces->map(fn (HelpCenterSpace $space) => [
            'id' => $space->id,
            'name' => $space->name,
            'url' => route('help-center.spaces.show', $space),
            'views' => $this->views($space, $request),
        ])->all();
    }

    /**
     * One Space's navigation (P4/P9/P22) — Overview, Inbox, the six queues, Settings.
     *
     * The same list the Help Center's own bar carries, narrowed to this Space: Unassigned means
     * "unassigned in here", and the counts are this Space's. Settings stays last, and the tab bar
     * pushes it to the right (P20) because what is on the left are the screens an agent works in.
     *
     * @return array<int, array<string, mixed>>
     */
    public function views(HelpCenterSpace $space, ?Request $request = null): array
    {
        $current = $request?->route('section');
        $currentSpace = $request === null ? null : self::routeSpaceId($request);
        $inThisSpace = $currentSpace === (int) $space->id;

        /*
         * Settings has its own routes now (P11), so it is not reachable through `{section}` and
         * its URL does not carry one — `route('section')` is null on every Settings page.
         *
         * Without this, the Settings tab went dark the moment you used it: you clicked it, you
         * were plainly on it, and the bar said you were on Overview (the fallback for a
         * section-less URL). Read from the route NAME instead, which is the thing that actually
         * changed.
         */
        if ($current === null && $request !== null
            && str_starts_with((string) $request->route()?->getName(), 'help-center.spaces.settings')) {
            $current = 'settings';
        }

        /*
         * A Request's own page belongs to the Inbox (P46).
         *
         * Same problem as Settings above and the same fix: `/spaces/{space}/requests/{request}`
         * carries no `{section}`, so the fallback lit Overview — a page reached by expanding a
         * ticket in the Inbox, insisting you were somewhere else. The nav should say where you
         * came from, which is the queue this ticket is in.
         */
        if ($current === null && $request !== null
            && $request->route()?->getName() === 'help-center.spaces.requests.page') {
            $current = 'inbox';
        }

        $counts = $this->countsFor($space);
        $rows = [];

        foreach ((array) config('help-center.space_sections') as $key => $section) {
            $rows[] = [
                'key' => $key,
                'label' => $section['label'],
                'icon' => $section['icon'],
                // Settings is a GROUP of pages, so its tab points at the group's front door
                // rather than at a `{section}` URL that no longer routes.
                'url' => $key === 'settings'
                    ? route('help-center.spaces.settings.index', ['space' => $space->id])
                    : route('help-center.spaces.section', ['space' => $space->id, 'section' => $key]),
                'count' => null,
                // The first section is where a Space opens, so it is also what is active when
                // the URL carries no section at all.
                'active' => $inThisSpace && ($current === $key || ($current === null && $key === self::firstSection())),
            ];

            /*
             * The views go straight after Inbox, and BEFORE Settings (P22).
             *
             * Spliced in rather than listed in `space_sections`, because they are not sections of
             * a Space in the way Overview and Settings are — they are the same six queues the
             * top-level bar carries, narrowed to this Space. One definition of what they are
             * (`request_views`), read at two scopes, rather than the labels and the order written
             * down twice.
             */
            if ($key !== 'inbox') {
                continue;
            }

            foreach (RequestViews::all() as $view => $item) {
                if (! ($item['nav'] ?? false)) {
                    continue;
                }

                $count = ($item['counted'] ?? false) ? (int) ($counts[$view] ?? 0) : 0;

                $rows[] = [
                    'key' => $view,
                    'label' => (string) $item['label'],
                    'icon' => (string) ($item['icon'] ?? 'inbox'),
                    'url' => route('help-center.spaces.section', ['space' => $space->id, 'section' => $view]),
                    // NULL, not 0 — "do not show a count when the value is 0", and a zero is a
                    // thing every template then has to remember not to print.
                    'count' => $count > 0 ? $count : null,
                    'active' => $inThisSpace && $current === $view,
                ];
            }
        }

        return $rows;
    }

    /**
     * One Space's counts, off the memoised map.
     *
     * @return array<string, int>
     */
    private function countsFor(HelpCenterSpace $space): array
    {
        $user = Auth::user();

        if ($user === null) {
            return [];
        }

        $this->spaceCounts ??= $this->views->countsBySpace((int) $user->id);

        return $this->spaceCounts[(int) $space->id] ?? [];
    }

    /** Where a Space opens — the first configured section. */
    public static function firstSection(): string
    {
        return (string) array_key_first((array) config('help-center.space_sections'));
    }

    /**
     * The id of the Space in the current URL, if there is one.
     *
     * `route('space')` gives back whatever the router bound — a HelpCenterSpace on the screens
     * that have one, a bare string elsewhere, null on the screens that have none. Casting that
     * to int directly is a fatal error the moment model binding succeeds, which is exactly the
     * case that matters, so the shape is checked rather than assumed.
     */
    public static function routeSpaceId(Request $request): ?int
    {
        $space = $request->route('space');

        if ($space instanceof HelpCenterSpace) {
            return (int) $space->id;
        }

        return is_numeric($space) ? (int) $space : null;
    }

    /**
     * Every Inbox the person may see, for the Inboxes screen (§14).
     *
     * Phase 1 has no per-inbox access list, so "accessible" is every Inbox in the workspace —
     * the tenant scope is the boundary. When §14's "Manage members" is built, this is the one
     * method that has to learn about it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function inboxes(User $user): array
    {
        return HelpCenterInbox::query()
            ->active()
            ->with(['space', 'emailAddresses'])
            ->orderBy('help_center_space_id')
            ->orderBy('position')
            ->get()
            ->map(fn (HelpCenterInbox $inbox) => $inbox->toPayload() + [
                'space_name' => $inbox->space?->name,
                'manageable' => $inbox->space !== null && $inbox->space->manageableBy($user),
            ])
            ->all();
    }
}
