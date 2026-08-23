<?php

namespace App\Http\Controllers\HelpCenter;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HelpCenter\Concerns\GuardsHelpCenter;
use App\Mail\HelpCenterAgentReplyMail;
use App\Models\HelpCenterCustomFieldValue;
use App\Models\HelpCenterEmailAddress;
use App\Models\HelpCenterMessage;
use App\Models\HelpCenterRequest;
use App\Models\HelpCenterRequestActivity;
use App\Models\HelpCenterRequestUpdate;
use App\Models\HelpCenterSpace;
use App\Models\HelpCenterStatus;
use App\Models\HelpCenterTag;
use App\Models\User;
use App\Services\HelpCenter\Inbound\AutoAssigner;
use App\Models\HelpCenterEmailTemplate;
use App\Services\HelpCenter\EmailTemplateRenderer;
use App\Services\HelpCenter\RatingManager;
use App\Services\HelpCenter\RequestActivity;
use App\Services\HelpCenter\SignatureResolver;
use App\Services\HelpCenter\SnoozeManager;
use App\Services\HelpCenter\SpaceSender;
use App\Services\RichTextSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Row actions on a Request (docs/features/help-center.md, P9 — Request Actions).
 *
 * ONE endpoint for every action the ••• menu offers. Assigning, changing status, changing
 * priority and marking spam are all "this Request changed"; a route per verb would be four
 * places that each have to remember who may write to a Request, and — more easily forgotten —
 * what a write does to its waiting clock.
 *
 * The rule the whole screen turns on:
 *
 *   **A Request may only be moved to a status belonging to its own Space's workflow.**
 *
 * Enforced here rather than trusted from the client, because the client's list of statuses came
 * from a page that may have been open since before somebody edited the workflow.
 */
class RequestController extends Controller
{
    use GuardsHelpCenter;

    /**
     * GET /help-center/spaces/{space}/requests/{request} — the detail drawer's contents (P32).
     *
     * Fetched when the drawer OPENS rather than shipped with the queue. A queue is a hundred
     * rows and a thread is every email on one of them; sending the bodies for all of them so
     * that one might be read is the difference between a page that loads and a page that
     * downloads somebody's mailbox.
     *
     * Found through the Space, like `update()` — see its note on why the id alone is not enough.
     */
    public function show(HelpCenterSpace $space, int $request): JsonResponse
    {
        $this->helpCenterWorkspace();
        abort_unless(Auth::user()->can('view', $space), 404);

        $model = HelpCenterRequest::query()
            ->forSpace($space->id)
            ->with(['status', 'assignee', 'tags', 'messages.attachments', 'customer', 'inbox',
                'snoozedBy', 'activity.actor', 'updates.author', 'notes.author'])
            ->findOrFail($request);

        return response()->json([
            'ok' => true,
            'request' => $model->toPayload() + [
                'space' => ['id' => $space->id, 'name' => $space->name],
                'manageable' => Auth::user()->can('update', $space),
            ],
            /*
             * The panel's read-only metadata (P33).
             *
             * Sent as formatted STRINGS rather than raw timestamps: every one of these is
             * displayed and none is computed with, and formatting a date in the browser is how
             * two screens end up disagreeing about what "Aug 21" means in another timezone.
             */
            'meta' => [
                // A Space owns exactly one workflow, so the Space's name IS the workflow's name.
                'workflow' => $space->name,
                'channel' => 'Email',
                'inbox' => $model->inbox?->name,
                'created' => $model->created_at?->format('M j, Y — g:i A'),
                'updated' => $model->last_activity_at?->format('M j, Y — g:i A'),
                'waiting' => $model->waitingLabel(),
                'waiting_on' => $model->waitingLabel() === null ? null : $model->waitingOn(),
                /*
                 * The snooze, as the dialog needs it (P45).
                 *
                 * `snoozed_by` is a NAME because it is displayed; `until_iso` is the only raw
                 * value here, and it exists so reopening the dialog on an already-snoozed ticket
                 * can seed the fields with what is actually set rather than with a fresh guess.
                 */
                'snooze' => $model->isSnoozed() ? [
                    'until' => $model->snoozed_until->format('M j, Y \a\t g:i A'),
                    'until_iso' => $model->snoozed_until->toIso8601String(),
                    'condition' => $model->snooze_condition,
                    'condition_label' => SnoozeManager::conditionLabel($model->snooze_condition),
                    'by' => $model->snoozedBy?->displayName(),
                    'since' => $model->snoozed_at?->diffForHumans(),
                ] : null,
                // The dropdown's options, from config so the server owns the vocabulary.
                'snooze_conditions' => collect(SnoozeManager::conditions())
                    ->map(fn (array $c, string $key) => [
                        'value' => $key,
                        'label' => $c['label'],
                        'help' => $c['help'] ?? null,
                    ])->values()->all(),
            ],
            'customer' => $this->customerPanel($model),
            /*
             * The Company & Customer block on the right-hand panel (P75 §10).
             *
             * A SEPARATE key from `customer` rather than nested inside it: the requirement draws
             * two blocks with two headings and two links, and the Company one exists even when
             * the Request has no customer record — an inbound with no usable address can still
             * be matched to a company by its domain.
             *
             * Null when the Space does not run the feature, which is what keeps the section off
             * the panel rather than a condition in the template.
             */
            'companyCustomer' => $this->companyCustomerPanel($model, $space),
            /*
             * The signature to pre-fill the composer with (P74).
             *
             * "The signature should appear in the reply composer so the agent can review or edit
             * it before sending." Resolved on the SERVER by the same `SignatureResolver` the send
             * uses, so what the agent sees is what would have gone out — a composer that built
             * its own would be a second answer to the priority order.
             *
             * Empty string when there is none, which the screen reads as "add nothing".
             */
            'signature' => app(SignatureResolver::class)->html($space, Auth::user()),
            'endpoints' => [
                // Templated on the update id, like every other per-row endpoint in this module.
                'noteStore' => route('help-center.spaces.requests.notes.store', [
                    'space' => $model->help_center_space_id, 'request' => $model->id,
                ]),
                'noteEdit' => route('help-center.spaces.requests.notes.update', [
                    'space' => $model->help_center_space_id, 'request' => $model->id, 'note' => '__ID__',
                ]),
                'noteDelete' => route('help-center.spaces.requests.notes.destroy', [
                    'space' => $model->help_center_space_id, 'request' => $model->id, 'note' => '__ID__',
                ]),
                'mentionUsers' => route('help-center.spaces.mentionable-users', [
                    'space' => $model->help_center_space_id,
                ]),
                'updateEdit' => route('help-center.spaces.requests.updates.update', [
                    'space' => $model->help_center_space_id, 'request' => $model->id, 'update' => '__ID__',
                ]),
                'updateDelete' => route('help-center.spaces.requests.updates.destroy', [
                    'space' => $model->help_center_space_id, 'request' => $model->id, 'update' => '__ID__',
                ]),
                'reply' => route('help-center.spaces.requests.reply', [
                    'space' => $model->help_center_space_id, 'request' => $model->id,
                ]),
                'update' => route('help-center.spaces.requests.updates.store', [
                    'space' => $model->help_center_space_id, 'request' => $model->id,
                ]),
                // One URL for both verbs (P45) — POST snoozes or reschedules, DELETE wakes.
                'snooze' => route('help-center.spaces.requests.snooze.store', [
                    'space' => $model->help_center_space_id, 'request' => $model->id,
                ]),
            ],
            'canReply' => Auth::user()->can('update', $space) && trim((string) $model->customer_email) !== '',
            /*
             * The timeline, in ONE list (P36).
             *
             * Messages, updates and activity rows sorted together by time, each carrying a
             * `kind` the tabs filter on — rather than three lists the client has to interleave
             * itself. "All" is the list; every other tab is a filter over it, which is what
             * keeps the six tabs from disagreeing about the order things happened in.
             */
            /*
             * The message that OPENED the ticket, on its own (P37).
             *
             * Pulled out of the timeline and shown under the subject, always — the question the
             * ticket is about should not depend on which tab is selected, and it was only
             * visible while All or Reply happened to be open.
             */
            'original' => $this->originalMessage($model),
            'timeline' => $this->timeline($model),
            'messages' => $model->messages->sortBy('id')->values()->map(fn (HelpCenterMessage $m) => [
                'id' => $m->id,
                'direction' => $m->direction,
                'inbound' => $m->isInbound(),
                /*
                 * The AUTHOR for an outbound message, the sender for an inbound one (P62).
                 *
                 * The timeline asks "who said this?", and since P62 an agent reply's `from_name`
                 * is the Space — correct on the wire and useless in a ticket, where every reply
                 * would otherwise read "Customer Support" whoever wrote it.
                 */
                'from_name' => $m->isInbound()
                    ? ($m->from_name ?: $m->from_email)
                    : ($m->author_name ?: $m->from_name ?: $m->from_email),
                'from_email' => $m->from_email,
                'to' => (array) $m->to_recipients,
                'cc' => (array) $m->cc_recipients,
                'subject' => $m->subject,
                /*
                 * The TEXT part, and HTML stripped only as a fallback.
                 *
                 * The same order the ingestor's preview and the assignment email use. Rendering
                 * a customer's raw `body_html` in our own page would be putting a stranger's
                 * markup — and their stylesheet, and whatever else came with it — inside the
                 * application's DOM; the drawer shows what they wrote, not how they wrote it.
                 */
                'body' => $this->readableBody($m),
                /*
                 * Only OUR OWN markup is served as HTML (P41).
                 *
                 * An agent's reply went through `RichTextSanitizer` on the way in, so rendering
                 * it is safe. An inbound email's `body_html` is a stranger's markup — their
                 * stylesheet, their tracking pixels, their idea of a layout — and it stays
                 * flattened to text by `readableBody()`. The drawer shows what the customer
                 * wrote, not how they wrote it.
                 */
                'body_html' => $m->isInbound() ? null : $m->body_html,
                'received_at' => $m->received_at?->format('M j, Y \a\t g:i A'),
                'received_ago' => $m->received_at?->diffForHumans(),
            ])->all(),
        ]);
    }

    /**
     * Messages, updates and activity as one chronological list (P36).
     *
     * `kind` is what every tab filters on:
     *
     *   All        — everything
     *   Activity   — kind `activity`
     *   Transition — kind `activity` with `transition`
     *   History    — kind `activity`, rendered old → new
     *   Updates    — kind `update`
     *
     * Agent replies and customer replies are both `message`, told apart by `inbound` — the
     * requirement's rule that a customer reply must never be merged into the agent reply above
     * it is satisfied by them being separate rows here, not by anything the client does.
     *
     * @return array<int, array<string, mixed>>
     */
    private function timeline(HelpCenterRequest $model): array
    {
        $rows = [];

        // The opening message is the headline now, so it is not also an entry — a ticket's own
        // question repeated two inches below itself is not a second thing that happened.
        $original = $model->messages->where('direction', HelpCenterMessage::DIRECTION_INBOUND)
            ->sortBy('id')->first();

        foreach ($model->messages->sortBy('id') as $m) {
            if ($original !== null && $m->id === $original->id) {
                continue;
            }

            $rows[] = [
                'id' => 'm'.$m->id,
                'kind' => 'message',
                'inbound' => $m->isInbound(),
                // The badge the requirement asks for, decided server-side so one vocabulary
                // exists for it.
                // The requirement's own two words (P62), and the same wording as the tab — an
                // agent reading "Agent Reply" under a tab called "Reply to Customer" has to
                // work out that they are the same thing.
                'label' => $m->isInbound() ? 'Customer Reply' : 'Reply to Customer',
                /*
                 * WHO WROTE IT, not what it was sent as (P62).
                 *
                 * An agent reply now goes out From the Space, so `from_name` on an outbound row
                 * is "Customer Support" — right on the wire, wrong here, where a timeline of
                 * replies would name the team five times and never the people. `author_name` is
                 * the person; inbound rows have no author and keep the sender.
                 */
                'author_name' => $m->isInbound()
                    ? ($m->from_name ?: $m->from_email)
                    : ($m->author_name ?: $m->from_name ?: $m->from_email),
                'author_email' => $m->isInbound() ? $m->from_email : ($m->author_email ?: $m->from_email),
                'initial' => mb_strtoupper(mb_substr((string) ($m->isInbound()
                    ? ($m->from_name ?: $m->from_email)
                    : ($m->author_name ?: $m->from_name ?: $m->from_email)), 0, 1)) ?: '?',
                'body' => $this->readableBody($m),
                'at' => $m->received_at?->format('M j, Y \a\t g:i A'),
                'ago' => $m->received_at?->diffForHumans(),
                'sort' => $m->received_at?->getTimestamp() ?? 0,

                // The files that came with it (P66).
                'attachments' => $this->attachments($m, $model),
                'attachments_skipped' => $this->skipped($m),

                /*
                 * The delivery facts the requirement asks the ticket to show (P64).
                 *
                 * Only on OUTBOUND rows. An inbound message was delivered by the customer's own
                 * server and we know nothing about it; printing "Sent" beside one would be
                 * claiming a fact we do not have.
                 */
                'delivery' => $m->isInbound() ? null : [
                    'status' => $m->delivery_status,
                    'failed' => $m->hasFailed(),
                    'error' => $m->delivery_error,
                    'at' => $m->delivered_at?->format('M j, Y \a\t g:i A'),
                    'to' => $model->customer_email,
                    'from' => $m->from_email,
                    'reply_to' => $m->reply_to,
                    'retry' => $m->hasFailed()
                        ? route('help-center.spaces.requests.retry', [
                            'space' => $model->help_center_space_id,
                            'request' => $model->id,
                            'message' => $m->id,
                        ])
                        : null,
                ],
            ];
        }

        foreach ($model->updates as $u) {
            /*
             * `id` is prefixed so message 7 and update 7 cannot collide as Vue keys; `raw_id` is
             * the number the edit and delete endpoints need.
             */
            $rows[] = $u->toPayload() + [
                'id' => 'u'.$u->id,
                'raw_id' => $u->id,
                'label' => 'Internal Update',
            ];
        }

        /*
         * The people named across every note, resolved ONCE.
         *
         * A note carries ids; the panel shows names. Looking each one up per note would be a
         * query per note on a ticket the team has been discussing.
         */
        $named = User::query()
            ->whereIn('id', $model->notes->flatMap(fn ($n) => (array) $n->mentions)->unique()->values())
            ->get();

        foreach ($model->notes as $n) {
            $rows[] = $n->toPayload($named) + ['id' => 'n'.$n->id, 'raw_id' => $n->id];
        }

        foreach ($model->activity as $a) {
            $rows[] = $a->toPayload() + ['id' => 'a'.$a->id, 'label' => 'Activity'];
        }

        usort($rows, fn (array $x, array $y) => $x['sort'] <=> $y['sort']);

        return $rows;
    }

    /**
     * The first inbound message — what the customer actually asked (P37).
     *
     * Null for a Request with no stored inbound message, which the panel renders as a note
     * rather than an empty box: a Request can exist without one (a test probe's Space, an
     * import), and a blank frame reads as a failure to load.
     *
     * @return array<string, mixed>|null
     */
    private function originalMessage(HelpCenterRequest $model): ?array
    {
        $m = $model->messages->where('direction', HelpCenterMessage::DIRECTION_INBOUND)
            ->sortBy('id')->first();

        if ($m === null) {
            return null;
        }

        return [
            'body' => $this->readableBody($m),
            'from_name' => $m->isInbound()
                ? ($m->from_name ?: $m->from_email)
                : ($m->author_name ?: $m->from_name ?: $m->from_email),
            'at' => $m->received_at?->format('M j, Y \a\t g:i A'),
            'ago' => $m->received_at?->diffForHumans(),
            /*
             * The opening message's files, on the HEADLINE (P66).
             *
             * This is the message that became the ticket, and it is rendered above the timeline
             * rather than in it — so its attachments have to travel with it or the most common
             * case of all, "customer emails in with a screenshot", would show the screenshot
             * nowhere.
             */
            'attachments' => $this->attachments($m, $model),
            'attachments_skipped' => $this->skipped($m),
        ];
    }

    /**
     * The Company & Customer section of the metadata panel (P75 §10).
     *
     * The Customer block, the Company block, and the enabled custom fields underneath — read
     * from the same tables the Company & Customer screen reads, so a ticket and a profile can
     * never disagree about what is recorded.
     *
     * Returns null when the Space has the master switch off. The panel then simply has no such
     * section, which is the difference between "this Space does not track companies" and "this
     * ticket has no company" — two states an empty block would render identically.
     *
     * @return array<string, mixed>|null
     */
    private function companyCustomerPanel(HelpCenterRequest $model, HelpCenterSpace $space): ?array
    {
        if (! $space->featureEnabled('company')) {
            return null;
        }

        $customer = $model->customer;
        $company = $model->company ?? $customer?->companyRecord;

        return [
            'customer' => $customer === null ? null : [
                'id' => $customer->id,
                'name' => $customer->displayName(),
                'email' => $customer->email,
                'phone' => $customer->phone,
                'external_id' => $customer->external_id,
                'initial' => mb_strtoupper(mb_substr($customer->displayName(), 0, 1)) ?: '?',
                'url' => route('help-center.company-customer.customer', ['customer' => $customer->id]),
                'fields' => $this->panelFields($space, HelpCenterCustomFieldValue::KIND_CUSTOMER, (int) $customer->id),
            ],
            'company' => $company === null ? null : [
                'id' => $company->id,
                'name' => $company->displayName(),
                'domain' => $company->domain,
                'phone' => $company->phone,
                'external_id' => $company->external_id,
                'initial' => mb_strtoupper(mb_substr($company->displayName(), 0, 1)) ?: '?',
                'url' => route('help-center.company-customer.company', ['company' => $company->id]),
                'fields' => $this->panelFields($space, HelpCenterCustomFieldValue::KIND_COMPANY, (int) $company->id),
            ],
        ];
    }

    /**
     * One record's ACTIVE custom fields with their values, for the panel (§10).
     *
     * This Space's fields only — unlike the profile screen, which unions every Space's, because
     * a ticket is worked in one Space and its panel should ask that Space's questions.
     *
     * Fields with no answer are dropped here, which the profile deliberately does not do: a
     * panel is a summary read at a glance, and a column of dashes is noise in a place that has
     * to stay short.
     *
     * @return array<int, array<string, mixed>>
     */
    private function panelFields(HelpCenterSpace $space, string $kind, int $ownerId): array
    {
        $switch = $kind === HelpCenterCustomFieldValue::KIND_COMPANY
            ? 'company_custom_fields'
            : 'customer_custom_fields';

        if (! $space->featureEnabled($switch)) {
            return [];
        }

        $fields = ($kind === HelpCenterCustomFieldValue::KIND_COMPANY
            ? $space->companyFields()
            : $space->customerFields())->where('is_active', true)->orderBy('position')->get();

        if ($fields->isEmpty()) {
            return [];
        }

        $values = HelpCenterCustomFieldValue::mapFor($kind, $ownerId);

        return $fields
            ->map(fn ($field) => [
                'id' => $field->id,
                'name' => $field->name,
                'type' => $field->type,
                'value' => $values[$field->id] ?? null,
            ])
            ->filter(fn (array $row) => trim((string) $row['value']) !== '')
            ->values()->all();
    }

    /**
     * The Ticket Sender block, and the tickets behind its count (P33).
     *
     * Falls back to the Request's OWN columns when there is no customer record — a Request
     * created before P33, or one whose sender had no usable address. The panel then shows the
     * name and email the message carried and no history, which is the truth rather than a gap.
     *
     * @return array<string, mixed>
     */
    private function customerPanel(HelpCenterRequest $model): array
    {
        $customer = $model->customer;

        if ($customer === null) {
            return [
                'id' => null,
                'name' => $model->customerLabel(),
                'email' => $model->customer_email,
                'initial' => mb_strtoupper(mb_substr($model->customerLabel(), 0, 1)) ?: '?',
                'total_tickets' => 1,
                'previous' => [],
            ];
        }

        /*
         * Their other tickets, most recent first, EXCLUDING this one.
         *
         * Five: the panel answers "have we heard from them before", not "show me everything" —
         * that is what a customer screen would be for. Each carries the URL of the Space it
         * lives in, because a customer's history crosses Spaces and a link into the wrong one
         * would 404.
         */
        $previous = $customer->requests()
            ->where('id', '!=', $model->id)
            ->with('status')
            ->orderByDesc('last_activity_at')
            ->limit(5)
            ->get()
            ->map(fn (HelpCenterRequest $r) => [
                'id' => $r->id,
                'identifier' => $r->ticketNumber(),
                'subject' => $r->subject ?: '(no subject)',
                'status' => $r->status?->name,
                'closed' => $r->isClosed(),
                'when' => $r->last_activity_at?->diffForHumans(),
                'url' => route('help-center.spaces.section', [
                    'space' => $r->help_center_space_id, 'section' => 'inbox',
                ]),
            ])->all();

        return $customer->toPanel() + [
            'previous' => $previous,
            'endpoint' => route('help-center.customers.update', ['customer' => $customer->id]),
        ];
    }

    /** A message's text, however it arrived. */
    /**
     * One message's files, as the ticket renders them (P66).
     *
     * @return array<int, array<string, mixed>>
     */
    private function attachments(HelpCenterMessage $message, HelpCenterRequest $model): array
    {
        return $message->attachments
            ->map(fn ($a) => $a->toPayload((int) $model->help_center_space_id))
            ->values()->all();
    }

    /**
     * What was NOT kept, and why (P66).
     *
     * Sent even though it is almost always empty, because the one time it is not is the time it
     * matters: an agent looking at "see the attached installer" with no attachment needs to know
     * the system refused it rather than that the customer forgot.
     *
     * @return array<string, mixed>|null
     */
    private function skipped(HelpCenterMessage $message): ?array
    {
        $count = (int) $message->attachments_skipped;

        if ($count < 1) {
            return null;
        }

        return [
            'count' => $count,
            'label' => $count === 1
                ? '1 attachment was not saved'
                : $count.' attachments were not saved',
            'items' => collect((array) $message->attachments_skipped_detail)
                ->map(fn ($row) => [
                    'name' => (string) ($row['name'] ?? 'attachment'),
                    'reason' => (string) ($row['reason'] ?? 'Not saved'),
                ])->values()->all(),
        ];
    }

    private function readableBody(HelpCenterMessage $message): string
    {
        $text = trim((string) $message->body_text);

        if ($text === '') {
            $text = trim(html_entity_decode(strip_tags((string) $message->body_html), ENT_QUOTES | ENT_HTML5));
        }

        // Runs of blank lines: a quoted reply arrives with a great many of them.
        return (string) preg_replace("/\n{3,}/", "\n\n", str_replace(["\r\n", "\r"], "\n", $text));
    }

    /**
     * POST /help-center/spaces/{space}/requests/{request}/reply — answer the customer (P36).
     *
     * Three things, in this order, and the order matters: store the message, send it, record
     * that it went. The row is written FIRST so a mail server that is slow or down loses the
     * delivery and not the reply — an agent who typed three paragraphs and got an error must
     * still find them on the ticket.
     */
    public function reply(
        Request $httpRequest,
        HelpCenterSpace $space,
        int $request,
        RequestActivity $activity,
        EmailTemplateRenderer $renderer,
    ): JsonResponse {
        $this->helpCenterWorkspace();
        abort_unless(Auth::user()->can('update', $space), 403);

        $model = HelpCenterRequest::query()
            ->forSpace($space->id)
            ->with(['messages', 'inbox.emailAddresses'])
            ->findOrFail($request);

        $data = $httpRequest->validate([
            'body' => ['required', 'string', 'max:40000'],
        ]);

        /*
         * The composer is the project's rich-text editor now (P41), so `body` arrives as HTML.
         *
         * Sanitized on the way IN, once, by the same `RichTextSanitizer` every other rich field
         * in this application uses — rather than escaped on every read. Anything the editor's
         * toolbar cannot produce is dropped.
         */
        $html = app(RichTextSanitizer::class)->sanitize($data['body']);

        if (trim(strip_tags((string) $html)) === '') {
            return response()->json(['ok' => false, 'message' => 'Write something first.'], 422);
        }

        $to = trim((string) $model->customer_email);

        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return response()->json([
                'ok' => false,
                'message' => 'This Request has no valid customer address to reply to.',
            ], 422);
        }

        /*
         * Spam gets no reply (P62 validation, and P47's rule that nothing further happens to it).
         *
         * Checked BEFORE the message is stored, unlike the inbound guard which stores first: an
         * agent typing into a spam ticket is a mistake to stop, where an inbound message on one
         * is a record to keep.
         */
        if ($model->is_spam) {
            return response()->json([
                'ok' => false,
                'message' => 'This ticket is marked as spam. Restore it before replying.',
            ], 422);
        }

        /*
         * WHO THIS IS FROM — resolved from the ticket's own Space (P62).
         *
         * Before anything is written, because a Space with no verified address cannot send and
         * storing the reply first would leave an agent looking at their own words on the ticket
         * with nothing having gone anywhere.
         */
        $sender = app(SpaceSender::class)->for($model);

        if (! $sender['ok']) {
            return response()->json(['ok' => false, 'message' => $sender['error']], 422);
        }

        $agent = Auth::user();

        /*
         * The message row, before the send.
         *
         * `to_recipients` is the customer; the thread it answers is the LAST inbound message,
         * which is what a mail client threads under — answering the first message of a long
         * thread puts the reply in the wrong place in their client.
         */
        $last = $model->messages->where('direction', HelpCenterMessage::DIRECTION_INBOUND)->last();

        $message = HelpCenterMessage::create([
            'tenant_id' => $model->tenant_id,
            'help_center_request_id' => $model->id,
            'direction' => HelpCenterMessage::DIRECTION_OUTBOUND,
            /*
             * The address this was SENT AS, not the agent's own (P62).
             *
             * The row is a record of the email that went out, and the email went out from the
             * Space. The agent is still identifiable — `author_email` keeps their address for the
             * per-agent reply counts the reporting service builds (P50) — but `from_email` now
             * matches what the customer actually saw in their client.
             */
            'from_email' => $sender['from_email'],
            'from_name' => $sender['from_name'],
            // The person, kept separately — see the migration for what depends on it.
            'author_email' => $agent->email,
            'author_name' => $agent->displayName(),
            // Recorded as sent, so the ticket shows what actually happened (P64).
            'reply_to' => $sender['reply_to'],
            'to_recipients' => [$to],
            'subject' => $model->subject,
            /*
             * BOTH forms are stored.
             *
             * `body_html` is what the agent wrote and what the customer is sent. `body_text` is
             * the same words flattened — the plain-text alternative every mail client can fall
             * back to, and what `readableBody()` reads when it renders a thread. A message row
             * with only markup in it would make the timeline depend on an HTML parser.
             */
            'body_html' => $html,
            'body_text' => trim(html_entity_decode(strip_tags(
                (string) preg_replace('/<\/(p|div|br|li|h[1-6])>/i', "\n", (string) $html),
            ), ENT_QUOTES | ENT_HTML5)),
            'received_at' => now(),
        ]);

        $sent = true;

        try {
            /*
             * Wrapped in the Space's Agent Reply template (P48).
             *
             * `{{reply_content}}` is where `$html` — the agent's own sanitized words — goes, and
             * `{{agent_signature}}` resolves through SignatureResolver: the agent's own, else the
             * Space default, else nothing. The agent typed neither.
             *
             * Composed here rather than in the Mailable so Settings can preview and test with the
             * same call. A Space whose template is somehow unresolvable falls back to the plain
             * reply the Mailable builds itself — better to send the words plainly than not at all.
             */
            $space = $model->space;

            $composed = $space === null ? null : $renderer->compose(
                $space,
                HelpCenterEmailTemplate::TYPE_AGENT_REPLY,
                // `{{support_email}}` is the address the customer can WRITE to — the Space's own,
                // not the ticket-tagged Reply-To, which is machinery rather than something to
                // print in an email body.
                // `true`: the composer was pre-filled with the signature (P74), so it is already
                // inside `$html`. Without this the customer gets it twice.
                $renderer->variables($model, $space, Auth::user(), (string) $html, $sender['from_email'], null, true),
            );

            Mail::to($to)->send(new HelpCenterAgentReplyMail(
                $model,
                $message,
                // From and Reply-To are one identity: the Space's inbound address under the
                // Space's display name (P64, P65).
                $sender['reply_to'],
                $last?->message_id,
                $composed['subject'] ?? null,
                $composed['html'] ?? null,
                $sender['from_email'],
                $sender['from_name'],
                $sender['reply_to_name'],
            ));
            /*
             * RECORDED on the row, not only in a toast (P64).
             *
             * A toast is gone the moment somebody looks away, and a stored reply that never
             * reached anybody looks exactly like one that did. The ticket is the thing an agent
             * comes back to, so the ticket is where the answer belongs.
             */
            $message->forceFill([
                'delivery_status' => HelpCenterMessage::DELIVERY_SENT,
                'delivered_at' => now(),
                'delivery_error' => null,
            ])->save();
        } catch (Throwable $e) {
            /*
             * Reported, not thrown.
             *
             * The reply is stored and visible on the ticket; what failed is the delivery. Losing
             * the whole request would lose the words too, and an agent cannot tell from a 500
             * which of the two happened.
             */
            $sent = false;

            $message->forceFill([
                'delivery_status' => HelpCenterMessage::DELIVERY_FAILED,
                'delivery_error' => mb_substr($e->getMessage(), 0, 2000),
            ])->save();

            Log::error('help-center.reply.failed', [
                'request_id' => $model->id,
                'to' => $to,
                'error' => $e->getMessage(),
            ]);
        }

        /*
         * Answering hands the wait back to the CUSTOMER.
         *
         * A Request the agent has just replied to is not one the agent is holding up, and an
         * Inbox sorted by longest wait would otherwise keep it at the top with the tickets
         * nobody has touched.
         */
        $model->forceFill([
            'last_activity_at' => now(),
            'waiting_since' => now(),
        ])->save();

        $activity->record($model, 'replied', meta: ['message_id' => $message->id, 'sent' => $sent]);

        return response()->json([
            'ok' => true,
            'sent' => $sent,
            /*
             * "Reply sent." — NOT "Reply sent to name@example.com."
             *
             * The address added nothing: the agent picked the ticket, the thread is on screen
             * with the customer's address at the top of it, and the recipient is never a choice
             * they made here. What it did do was put a customer's email address into a floating
             * banner, which is the one place on the screen that gets screen-shared, screenshotted
             * into a bug report and read over a shoulder.
             *
             * The failure line stays as it was — it never named the address, and its whole job
             * is to say the message is on the ticket rather than gone.
             */
            'message' => $sent
                ? 'Reply sent.'
                : 'Reply saved, but the email could not be sent. It is on the ticket.',
        ]);
    }

    /**
     * POST /help-center/spaces/{space}/requests/{request}/updates — an INTERNAL update (P36).
     *
     * Nothing is emailed. That is the whole distinction between this and `reply()`, and it is
     * kept structural rather than conditional: this method has no mailer and no recipient.
     */
    public function storeUpdate(
        Request $httpRequest,
        HelpCenterSpace $space,
        int $request,
    ): JsonResponse {
        $this->helpCenterWorkspace();
        abort_unless(Auth::user()->can('update', $space), 403);

        $model = HelpCenterRequest::query()->forSpace($space->id)->findOrFail($request);

        $data = $httpRequest->validate([
            'content' => ['required', 'string', 'max:40000'],
            'status' => ['required', 'string', 'in:'.implode(',', HelpCenterRequestUpdate::STATUSES)],
        ]);

        $data['content'] = (string) app(RichTextSanitizer::class)->sanitize($data['content']);

        if (trim(strip_tags($data['content'])) === '') {
            return response()->json(['ok' => false, 'message' => 'Write something first.'], 422);
        }

        $update = HelpCenterRequestUpdate::create([
            'tenant_id' => $model->tenant_id,
            'help_center_request_id' => $model->id,
            'author_id' => Auth::id(),
            'status' => $data['status'],
            'content' => $data['content'],
        ]);

        // The clock is NOT touched. An internal note is not an answer to the customer, and
        // moving the waiting period because somebody wrote to their colleagues would hide a
        // ticket that is still owed a reply.
        $model->forceFill(['last_activity_at' => now()])->save();

        return response()->json([
            'ok' => true,
            'update' => $update->fresh()->load('author')->toPayload(),
            'message' => 'Update posted.',
        ]);
    }

    /**
     * PATCH …/updates/{update} — edit an internal update (P39).
     *
     * Only the AUTHOR. An update is somebody's own words about where a ticket stands; anyone who
     * can manage the Space can delete one that should not be there, but rewriting what a
     * colleague said and leaving their name on it is a different thing entirely.
     */
    public function updateUpdate(
        Request $httpRequest,
        HelpCenterSpace $space,
        int $request,
        int $update,
    ): JsonResponse {
        $this->helpCenterWorkspace();
        abort_unless(Auth::user()->can('update', $space), 403);

        $model = HelpCenterRequest::query()->forSpace($space->id)->findOrFail($request);
        $row = HelpCenterRequestUpdate::query()
            ->where('help_center_request_id', $model->id)
            ->findOrFail($update);

        abort_unless((int) $row->author_id === (int) Auth::id(), 403, 'Only the author can edit an update.');

        $data = $httpRequest->validate([
            'content' => ['required', 'string', 'max:40000'],
            'status' => ['required', 'string', 'in:'.implode(',', HelpCenterRequestUpdate::STATUSES)],
        ]);

        $data['content'] = (string) app(RichTextSanitizer::class)->sanitize($data['content']);

        if (trim(strip_tags($data['content'])) === '') {
            return response()->json(['ok' => false, 'message' => 'Write something first.'], 422);
        }

        $row->forceFill($data + ['edited_at' => now()])->save();
        $model->forceFill(['last_activity_at' => now()])->save();

        return response()->json([
            'ok' => true,
            'update' => $row->fresh()->load('author')->toPayload(),
            'message' => 'Update saved.',
        ]);
    }

    /**
     * DELETE …/updates/{update} (P39).
     *
     * SOFT, like the work item's. An update somebody wrote and withdrew is still part of what
     * happened, and a hard delete would take it out of a timeline that claims to be complete.
     *
     * The author, or anyone who can manage the Space: removing something that should not be on a
     * ticket is moderation, and it should not require finding whoever wrote it.
     */
    public function destroyUpdate(HelpCenterSpace $space, int $request, int $update): JsonResponse
    {
        $this->helpCenterWorkspace();
        abort_unless(Auth::user()->can('update', $space), 403);

        $model = HelpCenterRequest::query()->forSpace($space->id)->findOrFail($request);

        HelpCenterRequestUpdate::query()
            ->where('help_center_request_id', $model->id)
            ->findOrFail($update)
            ->delete();

        return response()->json(['ok' => true, 'message' => 'Update deleted.']);
    }

    /** The address the customer writes to, for Reply-To. Verified first — see P27. */
    private function supportAddress(HelpCenterRequest $model): ?string
    {
        $addresses = $model->inbox?->emailAddresses;

        if ($addresses === null) {
            return null;
        }

        $verified = $addresses->firstWhere('status', HelpCenterEmailAddress::STATUS_VERIFIED);

        return ($verified ?? $addresses->first())?->email;
    }

    /**
     * POST …/requests/{request}/messages/{message}/retry — send a failed reply again (P64).
     *
     * Re-sends the message ALREADY STORED rather than asking the agent to retype it. That is the
     * whole point: their words survived, only the delivery did not, and a Retry that made them
     * write it again would be an apology rather than a fix.
     *
     * Only a FAILED outbound row can be retried. A successful one would deliver the same reply
     * twice, which is a worse outcome than the button not being there.
     */
    public function retry(
        HelpCenterSpace $space,
        int $request,
        int $message,
        EmailTemplateRenderer $renderer,
    ): JsonResponse {
        $this->helpCenterWorkspace();
        abort_unless(Auth::user()->can('update', $space), 403);

        $model = HelpCenterRequest::query()
            ->forSpace($space->id)
            ->with(['messages', 'inbox'])
            ->findOrFail($request);

        $row = $model->messages->firstWhere('id', $message);

        abort_if($row === null || $row->isInbound(), 404);

        if (! $row->hasFailed()) {
            return response()->json([
                'ok' => false,
                'message' => 'That reply has already been sent.',
            ], 422);
        }

        $to = trim((string) $model->customer_email);

        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return response()->json([
                'ok' => false,
                'message' => 'This Request has no valid customer address to reply to.',
            ], 422);
        }

        // Re-resolved rather than reused: the Space may have been fixed since the failure, which
        // is the most likely reason somebody is pressing Retry at all.
        $sender = app(SpaceSender::class)->for($model);

        if (! $sender['ok']) {
            return response()->json(['ok' => false, 'message' => $sender['error']], 422);
        }

        $last = $model->messages->where('direction', HelpCenterMessage::DIRECTION_INBOUND)->last();

        try {
            $composed = $space === null ? null : $renderer->compose(
                $space,
                HelpCenterEmailTemplate::TYPE_AGENT_REPLY,
                // Same as the send it is retrying: the stored body already carries whatever
                // signature the agent sent, so the tag must not add another (P74).
                $renderer->variables($model, $space, $row->author_email
                    ? User::where('email', $row->author_email)->first()
                    : null, (string) $row->body_html, $sender['from_email'], null, true),
            );

            Mail::to($to)->send(new HelpCenterAgentReplyMail(
                $model,
                $row,
                $sender['reply_to'],
                $last?->message_id,
                $composed['subject'] ?? null,
                $composed['html'] ?? null,
                $sender['from_email'],
                $sender['from_name'],
                $sender['reply_to_name'],
            ));

            $row->forceFill([
                'delivery_status' => HelpCenterMessage::DELIVERY_SENT,
                'delivered_at' => now(),
                'delivery_error' => null,
                'reply_to' => $sender['reply_to'],
                'from_email' => $sender['from_email'],
                'from_name' => $sender['from_name'],
            ])->save();
        } catch (Throwable $e) {
            $row->forceFill([
                'delivery_status' => HelpCenterMessage::DELIVERY_FAILED,
                'delivery_error' => mb_substr($e->getMessage(), 0, 2000),
            ])->save();

            Log::error('help-center.reply.retry_failed', [
                'request_id' => $model->id,
                'message_id' => $row->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'ok' => false,
                'message' => 'Still could not send — '.$e->getMessage(),
            ], 422);
        }

        // Same wording as `reply()` above, and for the same reason — a Retry is still a send,
        // and the two must not describe it differently.
        return response()->json(['ok' => true, 'message' => 'Reply sent.']);
    }

    /** PATCH /help-center/spaces/{space}/requests/{request} */
    public function update(
        Request $httpRequest,
        HelpCenterSpace $space,
        int $request,
        RequestActivity $activity,
    ): JsonResponse {
        $this->helpCenterWorkspace();
        abort_unless(Auth::user()->can('update', $space), 403);

        /*
         * Found THROUGH the Space, not by id alone.
         *
         * `forSpace` is what stops a Request belonging to another Space being reached by putting
         * its id in this Space's URL — the tenant scope alone would not, because both Spaces can
         * live in the same workspace (P3 §15).
         */
        $model = HelpCenterRequest::query()
            ->forSpace($space->id)
            ->with('status')
            ->findOrFail($request);

        $data = $httpRequest->validate([
            'status_id' => ['sometimes', 'nullable', 'integer'],
            'assignee_id' => ['sometimes', 'nullable', 'integer'],
            'priority' => ['sometimes', 'string', 'in:'.implode(',', array_keys((array) config('help-center.priorities')))],
            'is_spam' => ['sometimes', 'boolean'],
            'closed' => ['sometimes', 'boolean'],
            'tag_ids' => ['sometimes', 'array'],
            'tag_ids.*' => ['integer'],
        ]);

        if (array_key_exists('status_id', $data) && $data['status_id'] !== null) {
            $status = HelpCenterStatus::query()
                ->where('help_center_space_id', $space->id)
                ->find($data['status_id']);

            if ($status === null) {
                return response()->json([
                    'ok' => false,
                    'message' => "That status does not belong to this Space's workflow.",
                ], 422);
            }

            $before = $model->status?->name;

            $wasClosed = $model->isClosed();

            // Through the model, because a status change is also a clock change and a closure.
            $model->moveTo($status);

            /*
             * CSAT (P56): a status change may raise a rating request, or withdraw a pending one.
             *
             * Both live behind `RatingManager` so the eligibility rules are stated once. The
             * order matters — a ticket coming BACK from closed cancels first, so a Space that
             * both re-requests on reopen and triggers on resolved does not cancel the request it
             * just raised.
             */
            if ($wasClosed && ! $model->isClosed()) {
                app(RatingManager::class)->cancelPending($model);
            }

            app(RatingManager::class)->statusChanged($model, $status);

            // The row four tabs read (P36) — Activity, Transition, History and All.
            $activity->record(
                $model,
                HelpCenterRequestActivity::EVENT_STATUS,
                'status',
                $before,
                $status->name,
                ['from_id' => $model->getOriginal('help_center_status_id'), 'to_id' => $status->id],
            );

            /*
             * The new status's default-assignee rule, applied ONLY to an unowned Request (P28).
             *
             * P26 made those names mean something when mail arrives; the requirement asks that
             * moving a Request into a status apply them too. Never over an existing assignee:
             * dragging a ticket into "Escalated" must not take it off the person who escalated
             * it, and a rule that silently reassigns other people's work is a rule nobody will
             * trust twice.
             */
            /*
             * NOT on spam (P47).
             *
             * "Stop workflow automation, auto-assignment, reminders" — this is the module's only
             * auto-assignment on a status change, and handing somebody a ticket that has been
             * thrown away is exactly the automation the requirement is asking to stop. An agent
             * moving a spam ticket's status is still allowed; a rule acting on it is not.
             */
            if ($model->assignee_id === null && ! $model->is_spam) {
                $auto = app(AutoAssigner::class)->pick($status, (int) $space->id);

                if ($auto !== null) {
                    $model->forceFill(['assignee_id' => $auto, 'last_activity_at' => now()])->save();

                    // Recorded with a NULL actor and a `via`: nobody assigned this, the status's
                    // rule did, and a history that credits the person who changed the status is
                    // a history that misattributes every automatic assignment.
                    $activity->record(
                        $model,
                        HelpCenterRequestActivity::EVENT_ASSIGNED,
                        'assignee',
                        null,
                        User::find($auto)?->displayName(),
                        ['via' => 'workflow_rule', 'status' => $status->name],
                    );
                }
            }
        }

        if (array_key_exists('assignee_id', $data)) {
            /*
             * Only a member of THIS Space may be assigned.
             *
             * A workspace member who is not on the Space cannot see its Inbox, and assigning
             * somebody work they cannot open is a silent way to lose a customer's email.
             */
            $assignee = $data['assignee_id'];

            if ($assignee !== null && ! $space->members()->where('user_id', $assignee)->exists()) {
                return response()->json([
                    'ok' => false,
                    'message' => 'That person is not a member of this Space.',
                ], 422);
            }

            $before = $model->assignee?->displayName();

            $model->assignee_id = $assignee;
            $model->last_activity_at = now();
            $model->save();

            $activity->record(
                $model,
                HelpCenterRequestActivity::EVENT_ASSIGNED,
                'assignee',
                $before,
                $assignee === null ? null : User::find($assignee)?->displayName(),
                ['to_id' => $assignee],
            );
        }

        if (array_key_exists('priority', $data)) {
            $labels = (array) config('help-center.priorities');
            $before = $labels[$model->priority]['label'] ?? $model->priority;

            $model->forceFill(['priority' => $data['priority'], 'last_activity_at' => now()])->save();

            $activity->record(
                $model,
                HelpCenterRequestActivity::EVENT_PRIORITY,
                'priority',
                $before,
                $labels[$data['priority']]['label'] ?? $data['priority'],
            );
        }

        if (array_key_exists('tag_ids', $data)) {
            /*
             * Only tags belonging to THIS Space (P28).
             *
             * The same rule as the status one above and for the same reason: the client's list
             * came from a page that may have been open since before somebody deleted a tag, and
             * a Space's vocabulary is its own — one team's "Billing" is not another's.
             *
             * The ids are INTERSECTED rather than rejected. A tag deleted while this page was
             * open is not the sender doing anything wrong, and refusing the whole edit would
             * lose the four tags they did pick to punish the one that no longer exists.
             */
            $valid = HelpCenterTag::query()
                ->where('help_center_space_id', $space->id)
                ->whereIn('id', $data['tag_ids'])
                ->pluck('id')
                ->all();

            /*
             * KEYED by id, so each pivot row carries the tenant.
             *
             * `sync($ids, ['tenant_id' => …])` looks like it would do this and does not — the
             * second argument of `sync()` is `$detaching`, a boolean, so the attributes were
             * silently dropped and every insert failed on the NOT NULL column. Pivot attributes
             * only travel in the keys' values.
             */
            $before = $model->tags->pluck('name')->sort()->implode(', ');

            $model->tags()->sync(collect($valid)->mapWithKeys(
                fn (int $id) => [$id => ['tenant_id' => $model->tenant_id]],
            )->all());
            $model->forceFill(['last_activity_at' => now()])->save();
            $model->load('tags');

            // The NAMES, before and after — a history of tag ids is a history nobody can read.
            $activity->record(
                $model,
                HelpCenterRequestActivity::EVENT_TAGS,
                'tags',
                $before ?: null,
                $model->tags->pluck('name')->sort()->implode(', ') ?: null,
            );
        }

        /*
         * Close and reopen, from the ••• menu (P28).
         *
         * Routed through the WORKFLOW rather than by writing `closed_at` directly: a Space's
         * Closed state is one of its own statuses, and a Request that is closed without holding
         * that status would show as Closed on one screen and Open in the status column of the
         * next. `moveTo()` is the one place that knows what closing does to the clock.
         */
        if (array_key_exists('closed', $data)) {
            $target = $data['closed']
                ? HelpCenterStatus::query()->where('help_center_space_id', $space->id)
                    ->where('system_key', HelpCenterStatus::SYSTEM_CLOSED)->first()
                : HelpCenterStatus::query()->where('help_center_space_id', $space->id)
                    ->where('system_key', HelpCenterStatus::SYSTEM_OPEN)->first();

            if ($target === null) {
                return response()->json([
                    'ok' => false,
                    'message' => "This Space's workflow has no ".($data['closed'] ? 'Closed' : 'Open').' status.',
                ], 422);
            }

            $model->moveTo($target);

            /*
             * Closing ends any snooze (P45).
             *
             * A closed ticket is not waiting to come back, and leaving the columns set would put
             * it in the Snoozed count for a week after it was resolved. `REASON_SUPERSEDED`
             * writes no activity row: "status changed to Closed" already records this act, and
             * saying it twice is how a history becomes something people skim.
             */
            if ($data['closed']) {
                app(SnoozeManager::class)->unsnooze($model, SnoozeManager::REASON_SUPERSEDED);
            }
        }

        if (array_key_exists('is_spam', $data)) {
            /*
             * Spam stops the clock.
             *
             * Nobody owes a reply to spam, and leaving its timer running would put it at the top
             * of an Inbox sorted by longest wait — the one place it must never be.
             */
            $was = $model->is_spam ? 'yes' : 'no';

            /*
             * Marking stops the clock; RESTORING has to start it again (P47).
             *
             * `'waiting_since' => $model->waiting_since` looked like it left the clock alone on
             * the way back, and did — at null, because marking had just cleared it. A restored
             * ticket therefore returned to the queue reading "&mdash;", meaning nobody is waiting
             * on it, and sorted to the very bottom of an Inbox ordered by longest wait. The
             * recovery action quietly buried the thing it recovered.
             *
             * The original value is gone, so it is recomputed rather than remembered: the wait
             * belongs to whoever the current status says owes the next move, and it has been
             * running since the last message on the thread. That is the same reading `moveTo()`
             * takes, and it is closer to the truth than `now()` — a ticket wrongly marked as
             * spam yesterday was owed an answer yesterday, not from the moment somebody noticed.
             */
            $restoredClock = $model->waitingOn() === HelpCenterRequest::WAITING_NEITHER
                ? null
                : ($model->waiting_since ?? $model->last_message_at ?? now());

            $model->forceFill([
                'is_spam' => (bool) $data['is_spam'],
                'waiting_since' => $data['is_spam'] ? null : $restoredClock,
                'last_activity_at' => now(),
            ])->save();

            $activity->record(
                $model,
                HelpCenterRequestActivity::EVENT_SPAM,
                'is_spam',
                $was,
                $data['is_spam'] ? 'yes' : 'no',
            );

            // Spam stops the snooze for the same reason it stops the clock: nobody is waiting
            // for it to come back.
            if ($data['is_spam']) {
                app(SnoozeManager::class)->unsnooze($model, SnoozeManager::REASON_SUPERSEDED);
            }
        }

        $fresh = $model->fresh()->load(['status', 'assignee', 'tags', 'snoozedBy']);

        return response()->json([
            'ok' => true,
            'request' => $fresh->toPayload(),
            'message' => 'Request '.$fresh->ticketNumber().' updated.',
        ]);
    }
}
