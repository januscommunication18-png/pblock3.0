<?php

namespace App\Http\Controllers\HelpDesk;

use App\Models\HelpDeskActivity;
use Illuminate\Contracts\View\View;

/**
 * Help Desk › Settings › Activity (FR-1.9, §8).
 *
 * Who did what, in order, with the names things had at the time. Read-only by construction —
 * there is no endpoint here that writes, because an audit stream somebody can edit is not one.
 *
 * Visible to whoever may administer the Help Desk. An agent seeing that a colleague was
 * deactivated is a personnel matter, not an operational one.
 */
class ActivityController extends AreaController
{
    /** How many entries a page shows (§13: paginate activity lists). */
    private const PER_PAGE = 30;

    /** GET /help-desk/settings/activity */
    public function index(): View
    {
        $helpDesk = $this->helpDesk();
        $this->guardAdminister($helpDesk);

        $entries = HelpDeskActivity::query()
            ->where('help_desk_id', $helpDesk->id)
            // Eager-loaded: a page of thirty entries would otherwise be thirty actor lookups
            // (§13: no N+1 in list views).
            ->with('actor')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            // Shaped here rather than in the template, so the view renders strings and the
            // pager keeps working — `through` maps the page without losing the paginator.
            ->through(fn (HelpDeskActivity $entry) => [
                'actor' => $entry->actor?->displayName() ?? 'Someone',
                'initial' => $entry->actor?->initial() ?? '·',
                'color' => $entry->actor?->avatarColor() ?? '#94a3b8',
                'sentence' => $entry->sentence(),
                'at' => $entry->created_at?->format('M d, Y · H:i'),
            ]);

        return $this->page('settings.activity', [], [
            'helpDesk' => $helpDesk,
            'entries' => $entries,
        ]);
    }
}
