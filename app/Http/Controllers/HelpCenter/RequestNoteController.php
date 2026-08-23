<?php

namespace App\Http\Controllers\HelpCenter;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HelpCenter\Concerns\GuardsHelpCenter;
use App\Mail\MentionedMail;
use App\Models\HelpCenterRequest;
use App\Models\HelpCenterRequestNote;
use App\Models\HelpCenterSpace;
use App\Models\User;
use App\Services\MentionSync;
use App\Services\RichTextSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Internal notes on a Request (docs/features/help-center.md, P42).
 *
 * Its own controller rather than four more methods on `RequestController`, for one reason worth
 * stating plainly: **nothing in this file can send a customer an email.** There is no mailer
 * pointed at `customer_email` here, no Reply-To, no message row. The only mail it sends goes to
 * a colleague who was named, at their own address.
 *
 * That is the requirement's central rule — "Internal Notes must never be visible to the customer
 * and must never be included in customer-facing email threads" — expressed as structure rather
 * than as a condition somebody could get wrong.
 */
class RequestNoteController extends Controller
{
    use GuardsHelpCenter;

    public function __construct(
        private readonly RichTextSanitizer $richText,
        private readonly MentionSync $mentions,
    ) {}

    /**
     * GET /help-center/spaces/{space}/mentionable-users?search=
     *
     * Who the editor may offer when somebody types `@`. **Space members only** — the
     * requirement's permission rule, and the same principle the assignee picker follows: naming
     * somebody who cannot open the ticket is naming them at nothing.
     *
     * Gated on VIEWING the Space, like the work item's equivalent: looking somebody up is not
     * changing anything.
     */
    public function mentionable(Request $request, HelpCenterSpace $space): JsonResponse
    {
        $this->helpCenterWorkspace();
        abort_unless(Auth::user()->can('view', $space), 404);

        $needle = mb_strtolower(trim((string) $request->query('search', '')));

        $users = $this->spaceMembers($space)
            ->filter(function (User $user) use ($needle) {
                if ($needle === '') {
                    return true;
                }

                return str_contains(
                    mb_strtolower($user->displayName().' '.$user->full_name.' '.$user->email),
                    $needle,
                );
            })
            // A bounded list, whatever was typed — the same cap the work item's list uses.
            ->take((int) config('projects.mention_results', 10))
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->displayName(),
                'email' => $user->email,
                'initial' => $user->initial(),
                'avatar_url' => $user->avatar_url,
                'avatar_color' => $user->avatarColor(),
            ])
            ->values()
            ->all();

        return response()->json(['ok' => true, 'users' => $users]);
    }

    /** POST /help-center/spaces/{space}/requests/{request}/notes */
    public function store(Request $httpRequest, HelpCenterSpace $space, int $request): JsonResponse
    {
        $model = $this->authorised($space, $request);

        $data = $this->validated($httpRequest);
        $named = $this->named($space, $data['content']);

        $note = HelpCenterRequestNote::create([
            'tenant_id' => $model->tenant_id,
            'help_center_request_id' => $model->id,
            'author_id' => Auth::id(),
            'content' => $data['content'],
            'mentions' => $named->pluck('id')->all(),
        ]);

        /*
         * The clock is NOT touched, as with an update (P36).
         *
         * A note to colleagues is not an answer to the customer, and moving the waiting period
         * would hide a ticket that is still owed a reply behind one that has been discussed.
         */
        $model->forceFill(['last_activity_at' => now()])->save();

        $sent = $this->notify($named, $model, $space, $data['content']);

        return response()->json([
            'ok' => true,
            'note' => $note->fresh()->load('author')->toPayload($named),
            /*
             * The count is who was EMAILED, not who was named.
             *
             * Naming yourself is recorded and mails nobody (mentions §15), so a note that only
             * mentions its own author said "1 person notified" while sending nothing — a message
             * describing something that did not happen.
             */
            'message' => $sent === 0
                ? 'Note added.'
                : 'Note added — '.$sent.' '.($sent === 1 ? 'person' : 'people').' notified.',
        ]);
    }

    /**
     * PATCH …/notes/{note} — the AUTHOR only.
     *
     * The same rule an update follows (P39): anyone who can manage the Space may delete a note
     * that should not be there, but rewriting what a colleague said and leaving their name on it
     * is a different thing entirely.
     */
    public function update(Request $httpRequest, HelpCenterSpace $space, int $request, int $note): JsonResponse
    {
        $model = $this->authorised($space, $request);
        $row = $this->note($model, $note);

        abort_unless((int) $row->author_id === (int) Auth::id(), 403, 'Only the author can edit a note.');

        $data = $this->validated($httpRequest);

        $before = array_map('intval', (array) $row->mentions);
        $named = $this->named($space, $data['content']);

        $row->forceFill([
            'content' => $data['content'],
            'mentions' => $named->pluck('id')->all(),
            'edited_at' => now(),
        ])->save();

        /*
         * Only the NEWLY named are emailed.
         *
         * Editing a note to fix a typo must not tell everybody in it again — the same rule the
         * work item mentions follow (§11/§12): a notification is about being named, and being
         * named twice in one piece of content is one mention.
         */
        $this->notify($named->reject(fn (User $u) => in_array((int) $u->id, $before, true)), $model, $space, $data['content']);

        return response()->json([
            'ok' => true,
            'note' => $row->fresh()->load('author')->toPayload($named),
            'message' => 'Note saved.',
        ]);
    }

    /** DELETE …/notes/{note} — soft, so the record of the discussion survives. */
    public function destroy(HelpCenterSpace $space, int $request, int $note): JsonResponse
    {
        $model = $this->authorised($space, $request);

        $this->note($model, $note)->delete();

        return response()->json(['ok' => true, 'message' => 'Note deleted.']);
    }

    /** The one permission gate: managing the Space. Customers reach none of this. */
    private function authorised(HelpCenterSpace $space, int $request): HelpCenterRequest
    {
        $this->helpCenterWorkspace();
        abort_unless(Auth::user()->can('update', $space), 403);

        return HelpCenterRequest::query()->forSpace($space->id)->findOrFail($request);
    }

    private function note(HelpCenterRequest $model, int $note): HelpCenterRequestNote
    {
        return HelpCenterRequestNote::query()
            ->where('help_center_request_id', $model->id)
            ->findOrFail($note);
    }

    /** @return array{content: string} */
    private function validated(Request $request): array
    {
        $data = $request->validate(['content' => ['required', 'string', 'max:40000']]);

        // Sanitized on the way in, once, like every other rich field (P41).
        $content = (string) $this->richText->sanitize($data['content']);

        abort_if(trim(strip_tags($content)) === '', 422, 'Write something first.');

        return ['content' => $content];
    }

    /**
     * Who was actually named — parsed from the markup, then CHECKED.
     *
     * `MentionSync::parse()` reads the ids the editor wrote into the HTML; this keeps only the
     * ones who are members of this Space. The markup is presentation and somebody can send
     * anything; membership is the record. It is the same rule the work item mentions apply, with
     * a Space's members where a project's would be.
     *
     * @return Collection<int, User>
     */
    private function named(HelpCenterSpace $space, string $html): Collection
    {
        $ids = $this->mentions->parse($html);

        if ($ids->isEmpty()) {
            return collect();
        }

        return $this->spaceMembers($space)->whereIn('id', $ids->all())->values();
    }

    /**
     * Members of the Space with an account.
     *
     * An invited-but-not-accepted member has no user to name and no inbox to notify — the same
     * reason the assignee picker greys them out (P29).
     *
     * @return Collection<int, User>
     */
    private function spaceMembers(HelpCenterSpace $space): Collection
    {
        return $space->members()->with('user')->get()
            ->map(fn ($m) => $m->user)
            ->filter()
            ->unique('id')
            ->values();
    }

    /**
     * Tell the people who were named (P42).
     *
     * `MentionedMail` is the work item's, reused whole: the requirement asks for the existing
     * notification infrastructure rather than a second one, and the mail's fields map cleanly —
     * ticket number for identifier, subject for title, the Space for the project name.
     *
     * Never fatally, and never to yourself. Naming yourself in your own note is recorded because
     * the note genuinely says it; emailing you about it would be absurd (mentions §15).
     *
     * @param  Collection<int, User>  $people
     * @return int how many were actually emailed
     */
    private function notify(Collection $people, HelpCenterRequest $model, HelpCenterSpace $space, string $html): int
    {
        $actor = Auth::user();

        $recipients = $people
            ->reject(fn (User $u) => $actor && (int) $u->id === (int) $actor->id)
            ->filter(fn (User $u) => filter_var($u->email, FILTER_VALIDATE_EMAIL));

        if ($recipients->isEmpty()) {
            return 0;
        }

        /*
         * Straight to the ticket, with the Internal Notes tab open.
         *
         * `spaces.open` establishes the session and the workspace first — the same reasoning
         * every other emailed link in this module follows (P10, P26).
         */
        $url = route('help-center.spaces.open', ['space' => $space->id]).'?request='.$model->id.'&tab=notes';

        $mail = new MentionedMail(
            identifier: $model->ticketNumber(),
            title: (string) ($model->subject ?: '(no subject)'),
            projectName: (string) $space->name,
            actorName: $actor?->displayName() ?? 'Someone',
            where: 'an internal note',
            excerpt: (string) $this->richText->excerpt($html, (int) config('projects.excerpt.email')),
            url: $url,
        );

        foreach ($recipients as $user) {
            try {
                Mail::to($user->email)->send($mail);
            } catch (Throwable $e) {
                // The note is saved; what failed is a heads-up. Losing the request would lose
                // the note too, which is the thing worth keeping.
                Log::error('help-center.note.mention_failed', [
                    'request_id' => $model->id,
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $recipients->count();
    }
}
