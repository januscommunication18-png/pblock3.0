<?php

namespace App\Services\HelpCenter;

use App\Models\HelpCenterInbox;
use App\Models\HelpCenterSpace;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * What the Help Center sidebar draws (docs/features/help-center.md §13, §15, §16).
 *
 * Built here rather than in each controller for the reason WikiNavigation exists: the partial
 * rides along with every Help Center screen, so the list it shows must be assembled in ONE
 * place instead of in each of the five controllers that happen to render it.
 *
 * Counts (§17) are deliberately absent — they count conversations, and there are none yet
 * (HC-D8). The rows are built so adding a number later is a payload key, not a redesign.
 */
class HelpCenterNavigation
{
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
     * One Space's sections (P4) — Overview, Conversations, Inbox, Workflow, Members, Settings.
     *
     * These are the PARTS of a Space, which is what a Space's navigation should list. The six
     * conversation views of §16 are filters on the Conversations section, not siblings of it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function views(HelpCenterSpace $space, ?Request $request = null): array
    {
        $current = $request?->route('section');
        $currentSpace = $request === null ? null : self::routeSpaceId($request);
        $inThisSpace = $currentSpace === (int) $space->id;

        return collect((array) config('help-center.space_sections'))
            ->map(fn (array $section, string $key) => [
                'key' => $key,
                'label' => $section['label'],
                'icon' => $section['icon'],
                'url' => route('help-center.spaces.section', ['space' => $space->id, 'section' => $key]),
                // The first section is where a Space opens, so it is also what is active when
                // the URL carries no section at all.
                'active' => $inThisSpace && ($current === $key || ($current === null && $key === self::firstSection())),
            ])
            ->values()
            ->all();
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
