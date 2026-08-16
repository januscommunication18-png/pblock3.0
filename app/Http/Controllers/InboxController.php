<?php

namespace App\Http\Controllers;

use App\Models\InboxNotification;
use App\Models\Project;
use App\Services\ProjectNavigation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The Inbox — one person's attention items (docs/features/inbox.md).
 *
 * "Inbox = things that need my attention" (§46), which is why every list here is UNREAD by
 * default: a read notification is not history to browse, it is something already dealt with.
 *
 * Everything is scoped to the signed-in user by `for(Auth::id())` and nothing takes a user id
 * from the request, so "whose inbox is this?" is not a question this controller can get wrong.
 * Access to the CONTENT is re-checked when a row is opened (§31) — permissions change after a
 * notification is written, and the row must not become a way in.
 */
class InboxController extends Controller
{
    /** §22's tabs. `all` is the combined stream the screenshot leads with. */
    private const TABS = ['all', InboxNotification::TYPE_ASSIGNMENT, InboxNotification::TYPE_MENTION];

    public function __construct(private readonly ProjectNavigation $navigation) {}

    /** GET /inbox */
    public function index(Request $request): View
    {
        $user = Auth::user();
        $tab = in_array((string) $request->query('tab'), self::TABS, true)
            ? (string) $request->query('tab')
            : 'all';

        return view('inbox.index', [
            'user' => $user,
            'workspace' => $user->currentWorkspace,
            'projects' => $this->navigation->sidebarProjects($user),
            'canCreateProject' => $user->can('create', [Project::class, $user->currentWorkspace]),
            'bootstrap' => [
                'tab' => $tab,
                'items' => $this->rows($tab),
                'counts' => $this->counts(),
                'urls' => [
                    'list' => route('inbox.list'),
                    'read' => route('inbox.read', ['notification' => '__ID__']),
                    'readAll' => route('inbox.read-all'),
                    // The detail panel renders the work item through the same chrome-less
                    // frame the Views grid opens (§3): one detail view, not a second one.
                    'frame' => route('projects.work-items.frame', ['project' => '__PROJECT__', 'workItem' => '__ID__']),
                ],
            ],
        ]);
    }

    /** GET /inbox/list?tab=mention&search=WEB — the list, refreshed in place. */
    public function list(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tab' => ['nullable', 'string'],
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        $tab = in_array($data['tab'] ?? 'all', self::TABS, true) ? ($data['tab'] ?? 'all') : 'all';

        return response()->json([
            'ok' => true,
            'items' => $this->rows($tab, $data['search'] ?? null),
            'counts' => $this->counts(),
        ]);
    }

    /**
     * POST /inbox/{notification}/read — reviewed (§5/§6).
     *
     * Called by the client only AFTER the detail has loaded (§6/§40), so a notification is
     * never lost to a failed fetch. Idempotent: re-reading an already-read row is a no-op
     * rather than a second timestamp.
     */
    public function read(InboxNotification $notification): JsonResponse
    {
        abort_unless((int) $notification->recipient_id === (int) Auth::id(), 404);

        if (! $notification->isRead()) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return response()->json(['ok' => true, 'counts' => $this->counts()]);
    }

    /** POST /inbox/read-all — clears the stream you are looking at (§35). */
    public function readAll(Request $request): JsonResponse
    {
        $tab = in_array((string) $request->input('tab'), self::TABS, true)
            ? (string) $request->input('tab')
            : 'all';

        InboxNotification::query()
            ->for(Auth::id())
            ->unread()
            ->when($tab !== 'all', fn ($q) => $q->where('type', $tab))
            ->update(['read_at' => now()]);

        return response()->json(['ok' => true, 'counts' => $this->counts(), 'items' => $this->rows($tab)]);
    }

    /**
     * The unread counts the tabs and the topbar badge read (§22/§26).
     *
     * @return array<string, int>
     */
    private function counts(): array
    {
        $byType = InboxNotification::query()
            ->for(Auth::id())
            ->unread()
            ->selectRaw('type, count(*) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $assignment = (int) ($byType[InboxNotification::TYPE_ASSIGNMENT] ?? 0);
        $mention = (int) ($byType[InboxNotification::TYPE_MENTION] ?? 0);

        return [
            'assignment' => $assignment,
            'mention' => $mention,
            // §26: the navigation badge is the sum, not a third query.
            'all' => $assignment + $mention,
        ];
    }

    /**
     * One page of rows (§42).
     *
     * Lightweight by design: the row draws from columns frozen onto the notification, not from
     * a work item loaded per line. The full content is fetched only when a row is selected.
     *
     * @return array<int, array<string, mixed>>
     */
    private function rows(string $tab, ?string $search = null): array
    {
        $needle = trim((string) $search);

        return InboxNotification::query()
            ->for(Auth::id())
            ->unread()
            ->when($tab !== 'all', fn ($q) => $q->where('type', $tab))
            // §24: identifier, title, project or the excerpt — whichever the reader remembers.
            ->when($needle !== '', function ($q) use ($needle) {
                $like = '%'.$needle.'%';
                $q->where(function ($w) use ($like) {
                    $w->where('title', 'like', $like)
                        ->orWhere('excerpt', 'like', $like)
                        ->orWhereHas('workItem', fn ($i) => $i->where('identifier', 'like', $like))
                        ->orWhereHas('project', fn ($p) => $p->where('name', 'like', $like))
                        ->orWhereHas('actor', fn ($a) => $a->where('full_name', 'like', $like));
                });
            })
            ->with(['actor', 'project:id,name', 'workItem:id,identifier,title,project_id'])
            ->latest('created_at')
            ->limit((int) config('projects.inbox_page_size'))
            ->get()
            ->map(fn (InboxNotification $n) => $this->card($n))
            ->all();
    }

    /** @return array<string, mixed> */
    private function card(InboxNotification $n): array
    {
        return [
            'id' => $n->id,
            'type' => $n->type,
            'title' => $n->workItem?->title ?? $n->title,
            'identifier' => $n->workItem?->identifier,
            'excerpt' => $n->excerpt,
            'created_at' => $n->created_at?->toIso8601String(),
            'actor' => $n->actor ? [
                'id' => $n->actor->id,
                'name' => $n->actor->displayName(),
                'initial' => $n->actor->initial(),
                'avatar_url' => $n->actor->avatar_url,
                'avatar_color' => $n->actor->avatarColor(),
            ] : null,
            // No emoji: `projects` has no such column — the app reads `$project->emoji`
            // everywhere and gets null, so naming it in a constrained select is a lie.
            'project' => $n->project ? ['id' => $n->project->id, 'name' => $n->project->name] : null,
            'work_item_id' => $n->work_item_id,
            'project_id' => $n->project_id,
            // §13: a mention on a comment scrolls to that comment; an assignment just opens.
            'comment_id' => $n->comment_id,
            // §31: re-checked NOW, not when the notification was written. Permissions change,
            // and a stale row must not become a way into content somebody lost access to.
            'readable' => $n->workItem !== null && Auth::user()->can('view', $n->workItem),
        ];
    }
}
