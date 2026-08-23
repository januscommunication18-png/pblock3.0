<?php

namespace App\Http\Controllers\HelpCenter;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HelpCenter\Concerns\GuardsHelpCenter;
use App\Http\Requests\HelpCenter\UpdateSpaceSettingRequest;
use App\Http\Controllers\HelpCenter\SpaceController;
use App\Models\HelpCenterCompanyField;
use App\Models\HelpCenterMetadataMapping;
use App\Models\HelpCenterEmailTemplate;
use App\Models\HelpCenterRatingSettings;
use App\Models\HelpCenterSignature;
use App\Models\HelpCenterSpace;
use App\Models\HelpCenterSpaceSettings;
use App\Models\HelpCenterStatus;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\Services\HelpCenter\EligibleLeads;
use App\Services\HelpCenter\EmailTemplateRenderer;
use App\Services\HelpCenter\HelpCenterNavigation;
use App\Services\HelpCenter\WorkflowUpdater;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Space Settings (docs/features/help-center.md, P11).
 *
 * Deliberately built to the shape of `ProjectSettingsController`: a `{section}` URL, a nav list
 * in config, a per-section bootstrap payload, and a Vue panel mounted into `#settings-root`.
 * Configuring a Space and configuring a project should not feel like two applications.
 *
 * It replaced ONE read-only page that listed every setting a Space had. That page could not be
 * linked to, could not be deep-linked back into after a refresh, and had no way to change any
 * of what it showed — the values were written once by the setup wizard and then only displayed.
 * One routed page per section fixes all three at once.
 *
 * The section is a URL segment validated against config, so an unknown one 404s rather than
 * rendering an empty shell — the same rule the Space's own sections follow.
 */
class SpaceSettingsController extends Controller
{
    use GuardsHelpCenter;

    public function __construct(private readonly WorkflowUpdater $workflow) {}

    /**
     * GET /help-center/spaces/{space}/settings
     *
     * Settings has no page of its own — it is a group of pages — so the bare URL is a redirect
     * to the first one rather than a twelfth screen that would have to decide what to show.
     */
    public function index(HelpCenterSpace $space): mixed
    {
        $this->helpCenterWorkspace();
        abort_unless(Auth::user()->can('view', $space), 404);

        return redirect()->route('help-center.spaces.settings', [
            'space' => $space->id,
            'setting' => self::firstSection(),
        ]);
    }

    /** GET /help-center/spaces/{space}/settings/{setting} */
    public function show(Request $request, HelpCenterSpace $space, string $setting, HelpCenterNavigation $nav): View
    {
        $workspace = $this->helpCenterWorkspace();
        abort_unless(Auth::user()->can('view', $space), 404);

        $item = self::item($setting);
        abort_if($item === null, 404);

        $space->load([
            'inboxes.emailAddresses', 'settings', 'tags', 'statuses', 'members.user',
            // P75's page reads all three; loaded here rather than in the payload builder so the
            // one place that decides what a Settings page costs stays one place.
            'companyFields', 'customerFields', 'metadataMappings',
        ]);

        return view('help-center.space-settings', [
            'workspace' => $workspace,
            'section' => 'spaces',
            'space' => $space,
            // Keeps the Space's own tab bar rendering with Settings lit, so moving between
            // Overview/Inbox/Settings works the same from in here.
            'panel' => 'settings',
            'panelLabel' => $item['label'],
            'sections' => $nav->views($space, $request),
            'settingsNav' => $this->navFor($space),
            'setting' => $setting,
            'settingLabel' => $item['label'],
            // The template branches on this. Every other page is the one settings panel; Members
            // is the members grid, with its own root and its own scripts.
            'settingKind' => $item['kind'],
            'bootstrap' => $this->bootstrapFor($space, $item),
        ]);
    }

    /**
     * PATCH /help-center/spaces/{space}/settings/{setting}
     *
     * One endpoint, and each panel sends only its own fields. The alternative — one endpoint
     * per setting — would be eleven places that each have to agree about who may write to a
     * Space's configuration and about what a write does to the row that may not exist yet.
     *
     * `UpdateSpaceSettingRequest` reads the section from the route and validates only the keys
     * that section owns, so a panel cannot reach a field it does not render.
     */
    public function update(UpdateSpaceSettingRequest $request, HelpCenterSpace $space, string $setting): JsonResponse
    {
        $this->helpCenterWorkspace();
        abort_unless(Auth::user()->can('update', $space), 403);

        $item = self::item($setting);
        abort_if($item === null || ($item['status'] ?? '') === 'soon', 404);

        $data = $request->validated();

        /*
         * Workflow is not a setting on the settings row — it is the Space's statuses — so it is
         * handled before that row is touched and returns its own payload.
         *
         * `WorkflowUpdater` matches rows by id rather than recreating them, because Requests
         * point at status ids: a delete-and-recreate would detach every conversation in the
         * Inbox from its status.
         */
        if ($item['kind'] === 'workflow') {
            $this->workflow->sync($space, (array) $data['statuses']);

            return response()->json([
                'ok' => true,
                'statuses' => $this->workflowStatuses($space->fresh()),
                'message' => 'Workflow saved.',
            ]);
        }

        /*
         * The Inbox panel saves the Space's SENDER NAME (P65), which is a column on the Space
         * rather than on the settings row — so, like workflow above, it is handled before that
         * row is touched and returns its own payload.
         *
         * The inbound ADDRESS is not accepted here at all: it is generated with the Inbox and
         * the requirement makes it read-only, so there is no branch that could write it.
         */
        if ($item['kind'] === 'inbox') {
            $name = trim((string) ($data['inbound_display_name'] ?? ''));

            // Blank is NULL, not "" — `senderName()` falls back to the Space name, and an empty
            // string stored here would be a From header with an empty quoted display name.
            $space->forceFill(['inbound_display_name' => $name === '' ? null : $name])->save();

            return response()->json([
                'ok' => true,
                'senderName' => $space->senderName(),
                'message' => 'Sender name saved.',
            ]);
        }

        /*
         * The settings row is created on demand.
         *
         * A Space built before the wizard's settings step existed has none (the old screen said
         * so and stopped there), and a Space whose settings were never saved is not a Space
         * without settings — it is a Space running on the defaults. Writing the defaults down
         * the first time somebody changes one of them is what makes this page work for those
         * Spaces instead of erroring on a missing row.
         */
        $cfg = $space->settings ?: new HelpCenterSpaceSettings([
            'tenant_id' => $space->tenant_id,
            'help_center_space_id' => $space->id,
            'metadata' => HelpCenterSpaceSettings::defaultMetadata(),
            'reassign_destination' => HelpCenterSpaceSettings::DESTINATION_UNASSIGNED,
        ]);

        match ($item['kind']) {
            'metadata' => $this->applyMetadata($cfg, (string) $item['metadata'], (bool) $data['enabled']),
            // Company & Customer (P75 §2): five switches written as one map, so the master and
            // its sub-switches can never be half-saved.
            'company_customer' => $this->applyFeatures($cfg, (array) $data['features']),
            'auto_bcc' => $cfg->forceFill([
                'auto_bcc_enabled' => (bool) $data['auto_bcc_enabled'],
                /*
                 * Cleared when switched off, rather than kept as a list nothing reads: stored
                 * addresses behind a disabled toggle are a thing the next reader has to decide
                 * the meaning of.
                 *
                 * Duplicates are dropped on the way in as well as refused by the request. The
                 * request explains WHICH address is repeated, which is what somebody needs to
                 * fix it; this makes sure a list that reached the column cannot hold one twice.
                 */
                'auto_bcc_emails' => $data['auto_bcc_enabled']
                    ? array_values(array_unique((array) ($data['auto_bcc_emails'] ?? [])))
                    : [],
            ]),
            'reassignment' => $cfg->forceFill([
                'reassign_enabled' => (bool) $data['reassign_enabled'],
                // Stored as total minutes (HC-D16) — the Hours + Minutes pair is presentation.
                'reassign_after_minutes' => ((int) $data['reassign_hours'] * 60) + (int) $data['reassign_minutes'],
                'reassign_destination' => $data['reassign_destination'],
            ]),
            'auto_follow' => $cfg->forceFill(['auto_follow_mentions' => (bool) $data['enabled']]),
            // `inbox` is handled above — its one saveable field lives on the Space. Everything
            // else on that panel writes through the address endpoints.
            default => abort(404),
        };

        $cfg->save();
        $space->setRelation('settings', $cfg);

        return response()->json([
            'ok' => true,
            'settings' => $cfg->toPayload(),
            'message' => 'Saved.',
        ]);
    }

    /**
     * One metadata switch, written back into the map the others share.
     *
     * Read-modify-write on purpose: `metadata` is a single JSON column holding all seven
     * toggles, so a panel that owns one of them must not send the whole map — it does not know
     * what the other six are, and sending its own idea of them is how a page that was open in
     * another tab reverts a change somebody just made.
     */
    private function applyMetadata(HelpCenterSpaceSettings $cfg, string $key, bool $enabled): void
    {
        $meta = (array) config("help-center.metadata.$key");

        // Belt and braces: the request already refuses an unavailable toggle, and the route
        // already 404s a Coming Soon section. This is the third door onto the same rule and it
        // is closed too, because "coming soon" is a fact about the feature (HC-D18).
        abort_if($enabled && ! ($meta['available'] ?? false), 422, ($meta['label'] ?? 'This setting').' is coming soon.');

        $cfg->forceFill([
            'metadata' => array_merge(
                HelpCenterSpaceSettings::defaultMetadata(),
                (array) $cfg->metadata,
                [$key => $enabled],
            ),
        ]);
    }

    /**
     * Write a group of metadata switches at once (P75 §2).
     *
     * Merged over the defaults and then over what is stored, the same order `applyMetadata`
     * uses: a Space whose row predates a switch keeps that switch's default rather than gaining
     * a `false` it never chose.
     *
     * The sub-switches are NOT cleared when the master goes off. `featureEnabled()` already
     * gates them at read time, so turning Company & Customer off hides the module without
     * forgetting how it was configured — and turning it back on restores what was there rather
     * than a set of defaults.
     *
     * @param  array<string, bool>  $features
     */
    private function applyFeatures(HelpCenterSpaceSettings $cfg, array $features): void
    {
        foreach ($features as $key => $enabled) {
            $meta = (array) config('help-center.metadata.'.$key);

            // The third door onto the Coming Soon rule, closed like the other two (HC-D18).
            abort_if(
                $enabled && ! ($meta['available'] ?? false),
                422,
                ($meta['label'] ?? 'This setting').' is coming soon.',
            );
        }

        $cfg->forceFill([
            'metadata' => array_merge(
                HelpCenterSpaceSettings::defaultMetadata(),
                (array) $cfg->metadata,
                array_map(fn ($v) => (bool) $v, $features),
            ),
        ]);
    }

    /**
     * What each panel boots with.
     *
     * @return array<string, mixed>
     */
    private function bootstrapFor(HelpCenterSpace $space, array $item): array
    {
        $cfg = $space->settings;
        $canManage = Auth::user()->can('update', $space);

        $common = [
            'kind' => $item['kind'],
            'title' => $item['label'],
            'canManage' => $canManage,
            'endpoint' => route('help-center.spaces.settings.update', [
                'space' => $space->id,
                'setting' => $item['key'],
            ]),
        ];

        return match ($item['kind']) {
            'metadata' => $common
                + $this->metadataPayload($cfg, (string) $item['metadata'])
                + $this->managedPayload($space, $item),
            /*
             * The Space's email templates and signatures (P48).
             *
             * Every template is resolved rather than fetched, so a Space that has never touched
             * this page opens on the packaged defaults with the editor already populated — the
             * alternative is three blank forms and no way to see what is actually being sent.
             */
            'email_templates' => $common + $this->emailTemplatePayload($space),

            /*
             * Company & Customer (P75 §2–§4) — five switches, two field lists and the mapping
             * table, on one page.
             *
             * It was `kind => metadata, manages => company_fields`: one toggle and one list.
             * The requirement outgrew what `manages` can bolt onto a panel built around a single
             * `enabled` boolean, so it became its own kind rather than a third special case
             * inside that one.
             */
            'company_customer' => $common + $this->companyCustomerPayload($space),

            /*
             * CSAT (P56). The settings, plus every vocabulary the page's pickers and its live
             * customer preview need — all from config, so the preview renders the same scale the
             * customer will actually see.
             */
            'rating' => $common + [
                'description' => 'Ask customers how their support experience went, and act on what they say.',
                'settings' => HelpCenterRatingSettings::for($space)->toPayload(),
                'types' => collect((array) config('help-center.rating_types'))
                    ->map(fn (array $t, string $k) => [
                        'value' => $k, 'label' => $t['label'], 'points' => $t['points'],
                    ])->values()->all(),
                'triggers' => collect((array) config('help-center.rating_triggers'))
                    ->map(fn (string $l, string $k) => ['value' => $k, 'label' => $l])->values()->all(),
                'delays' => collect((array) config('help-center.rating_delays'))
                    ->map(fn (string $l, $k) => ['value' => (int) $k, 'label' => $l])->values()->all(),
                'requirements' => collect((array) config('help-center.rating_comment_requirements'))
                    ->map(fn (string $l, string $k) => ['value' => $k, 'label' => $l])->values()->all(),
                'defaultLabels' => (array) config('help-center.rating_labels'),
                'statuses' => $space->statuses->sortBy('position')->values()
                    ->map(fn ($s) => ['value' => $s->id, 'label' => $s->name])->all(),
                'tags' => $space->tags->map(fn ($t) => ['value' => $t->id, 'label' => $t->name])->values()->all(),
                /*
                 * `ratingEndpoint`, NOT `endpoint`.
                 *
                 * `$common` already carries `endpoint` — the shared settings PATCH — and this is
                 * built as `$common + [...]`, where PHP keeps the LEFT operand's keys. Naming
                 * this one `endpoint` would have been silently dropped and the Save button would
                 * have written to the wrong URL. The same near-miss P48 hit with
                 * `endpoint`/`endpoints`; a distinct name is the fix that cannot recur.
                 */
                'ratingEndpoint' => route('help-center.spaces.rating.update', ['space' => $space->id]),

                /*
                 * The rating request template, edited from a modal on THIS page (P60).
                 *
                 * Deliberately the same three keys the Email Template page sends — `templates`,
                 * `variables`, `endpoints` — because the modal reuses that page's methods
                 * unchanged (`editTemplate`, `saveTemplate`, `previewTemplate`,
                 * `insertVariable`). One template editor, two doors onto it: the requirement is
                 * explicit that both must edit the same record and never create a second.
                 *
                 * Only the one type travels, so the modal cannot be pointed at another.
                 */
                'templates' => [
                    app(EmailTemplateRenderer::class)
                        ->resolve($space, HelpCenterEmailTemplate::TYPE_RATING_REQUEST)
                        + [
                            'label' => config('help-center.email_templates.rating_request.label'),
                            'description' => config('help-center.email_templates.rating_request.description'),
                            'can_disable' => (bool) config('help-center.email_templates.rating_request.can_disable'),
                        ],
                ],
                'variables' => (array) config('help-center.email_variables'),
                'endpoints' => [
                    'save' => route('help-center.spaces.email-templates.update', [
                        'space' => $space->id, 'type' => '__TYPE__',
                    ]),
                    'reset' => route('help-center.spaces.email-templates.reset', [
                        'space' => $space->id, 'type' => '__TYPE__',
                    ]),
                    'preview' => route('help-center.spaces.email-templates.preview', [
                        'space' => $space->id, 'type' => '__TYPE__',
                    ]),
                ],
                'editorLicense' => (string) config('projects.jodit_license'),

                // Still linked, for somebody who wants the other three templates too.
                'templateUrl' => route('help-center.spaces.settings', [
                    'space' => $space->id, 'setting' => 'email-template',
                ]),
            ],

            'auto_bcc' => $common + [
                'description' => 'Send a blind copy of every message in this Space to other addresses.',
                'enabled' => (bool) $cfg?->auto_bcc_enabled,
                'emails' => $cfg?->bccEmails() ?? [],
                'max' => HelpCenterSpaceSettings::bccMax(),
            ],
            'reassignment' => $common + [
                'description' => 'Move a conversation on when the agent it is assigned to has been away too long.',
                'enabled' => (bool) $cfg?->reassign_enabled,
                'after' => $cfg?->threshold() ?? ['hours' => 0, 'minutes' => 0],
                'destination' => (string) ($cfg?->reassign_destination ?? HelpCenterSpaceSettings::DESTINATION_UNASSIGNED),
                'destinations' => collect((array) config('help-center.reassign_destinations'))
                    ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                    ->values()->all(),
                // Honest about what is stored versus what runs (HC-D17).
                'note' => 'Reassignment is saved with this Space and takes effect once conversations arrive.',
            ],
            'auto_follow' => $common + [
                'description' => 'Add an agent as a follower of any conversation they are mentioned in, so replies reach them without anyone having to remember to subscribe them.',
                'toggleLabel' => 'Auto Follow on Mention',
                'enabled' => (bool) $cfg?->auto_follow_mentions,
                'note' => 'Auto-follow is saved with this Space and takes effect once conversations arrive.',
            ],
            /*
             * The Space's status list (P15) — moved here from the Space's own tab bar.
             *
             * Read-only, exactly as it was before the move. Editing a workflow is the wizard's
             * step 4 today; giving it a second, different editor here would be two places that
             * have to agree about what a protected status is.
             */
            'workflow' => $common + [
                /*
                 * The fixed System Categories (P53), shown on this page's second tab.
                 *
                 * Sent as data rather than written into the screen, so the one place they are
                 * defined is `config('help-center.system_categories')` — the panel renders
                 * whatever it is given and has no list of its own to drift from it.
                 */
                'systemCategories' => collect((array) config('help-center.system_categories'))
                    ->map(fn (array $c, string $key) => [
                        'key' => $key,
                        'label' => $c['label'],
                        'color' => $c['color'] ?? '#9ca3af',
                        'description' => $c['description'] ?? null,
                    ])->values()->all(),
                'description' => 'The states a Request can be in. Every Request in this Space '
                    .'takes its status from this list and from nowhere else.',
                'statuses' => $this->workflowStatuses($space),
                // What the editor's pickers offer, from the model's own vocabularies rather
                // than from lists typed into a template.
                'responsibilities' => [
                    ['value' => HelpCenterStatus::RESPONSIBILITY_ASSIGNEE, 'label' => 'Assignee'],
                    ['value' => HelpCenterStatus::RESPONSIBILITY_CREATOR, 'label' => 'Creator'],
                ],
                'waitingOptions' => [
                    ['value' => HelpCenterStatus::WAITING_AGENT, 'label' => 'Agent'],
                    ['value' => HelpCenterStatus::WAITING_CUSTOMER, 'label' => 'Customer'],
                    ['value' => HelpCenterStatus::WAITING_NEITHER, 'label' => 'Nobody'],
                ],
                /*
                 * The System Category picker's options (P54).
                 *
                 * The same five the System Category tab lists, from the same config — one
                 * vocabulary, so the tab that explains them and the field that assigns them can
                 * never offer different sets.
                 */
                'categoryOptions' => collect((array) config('help-center.system_categories'))
                    ->map(fn (array $c, string $key) => ['value' => $key, 'label' => $c['label']])
                    ->values()->all(),
                'colors' => array_values((array) config('help-center.status_colors')),
                'statusMax' => (int) config('help-center.status_max', 20),
                'nameMax' => (int) config('help-center.status_max_length', 60),
                // Only this Space's members: assigning to somebody who is not on the Space is
                // assigning to somebody who cannot see the Request. The writer enforces it.
                'assigneeOptions' => $space->members
                    ->map(fn ($m) => $m->user ? ['value' => (string) $m->user->id, 'label' => $m->user->full_name] : null)
                    ->filter()->values()->all(),
                'note' => 'Open and Closed are protected: they cannot be renamed, reordered or '
                    .'removed. Everything between them is yours.',
            ],

            /*
             * The channel list (P12). Read-only in this phase, so there is nothing to send and
             * no `enabled` to store: Email is on because the Space HAS an Inbox and an inbound
             * address, which the Inbox panel above is the configuration for.
             */
            'channels' => $common + [
                'description' => 'Choose how customers can contact this support space.',
                'channels' => collect((array) config('help-center.channels'))
                    ->map(fn (array $c) => $c + ['enabled' => ($c['status'] ?? 'soon') === 'active'])
                    ->values()->all(),
                'note' => 'Email Support is enabled for every Space. Its inbound address and the '
                    .'customer-facing addresses that feed it are configured on the Inbox page.',
            ],

            /*
             * Same payload the Inboxes screen boots with, so this panel can do what that screen
             * does rather than send people to it (P11). `toPayload()` is reused rather than
             * hand-built here for exactly that reason: two shapes for one row is how the two
             * screens would start disagreeing about what a status is called.
             */
            'inbox' => $common + [
                'description' => 'Where this Space receives email. The inbound address is generated and cannot be changed; the customer-facing addresses are the ones your customers actually write to.',
                'inboxes' => $space->inboxes->map(fn ($inbox) => $inbox->toPayload() + [
                    // Managing an address is managing the Space, which is the permission this
                    // whole screen already turns on — the endpoints re-check it regardless.
                    'manageable' => $canManage,
                ])->values()->all(),
                'endpointTemplates' => [
                    'addresses' => route('help-center.addresses.store', ['inbox' => '__ID__']),
                    'address' => route('help-center.addresses.destroy', ['inbox' => '__ID__', 'address' => '__ADDRESS__']),
                    // POST starts the probe, GET is what the row polls — same URL, so the panel
                    // builds one string and picks the verb.
                    'addressTest' => route('help-center.addresses.test', ['inbox' => '__ID__', 'address' => '__ADDRESS__']),
                ],

                /*
                 * The Space's own configuration, and the way to delete it (P52).
                 *
                 * On this page because it is where somebody already comes to answer "how is this
                 * Space set up?" — the inbound address is the first thing they check (P8), and
                 * the lead, the type and the workflow are the rest of the same question.
                 *
                 * The rows come from `SpaceController::configurationRows()` (P51), so the panel
                 * here and the dialog on the Overview cannot describe the same Space differently.
                 */
                'configuration' => SpaceController::configurationRows($space),
                'spaceName' => (string) $space->name,

                /*
                 * The sender name (P65), and everything the panel needs to show the preview the
                 * requirement asks for: `eBay Support <inbox-…@inbound…>`.
                 *
                 * `displayName` is the RAW column — empty when nobody has set one — so the input
                 * renders empty and its placeholder can show the default rather than pretending
                 * somebody typed it. `senderName` is what will actually be sent, which is what
                 * the preview must show.
                 */
                'displayName' => (string) ($space->inbound_display_name ?? ''),
                'senderName' => $space->senderName(),
                'inboundAddress' => $space->inboxes->first()?->inboundAddress(),
                /*
                 * A SEPARATE permission from managing the Space.
                 *
                 * `update` is enough to rename it or change its workflow; deleting it takes the
                 * conversations, the workflow and the inbound address with it. The policy already
                 * draws that line and this reads it rather than reusing `$canManage`.
                 */
                'canDelete' => Auth::user()->can('delete', $space),
                'deleteEndpoint' => route('help-center.spaces.destroy', ['space' => $space->id]),
                'spacesUrl' => route('help-center.spaces.index'),
            ],
            /*
             * Who works this Space (P10) — moved here from the Space's own tab bar.
             *
             * `$common` still travels with it even though nothing in this payload is saved
             * through the settings endpoint: the panel is rendered inside the Settings shell and
             * `title` is what names it, and `canManage` is the same permission every control on
             * the grid is gated on. Members write through the member routes in `endpoints`
             * below, which is why `update()` has no `members` branch — a PATCH to the settings
             * endpoint for this section 404s rather than silently accepting a body.
             */
            'members' => $common + $this->membersPayload($space),

            default => $common,
        };
    }

    /**
     * The Members screen (docs/features/help-center.md, P10).
     *
     * Shaped like Project Settings › Members' payload, because it drives the same grid.
     * `candidates` is everyone in the workspace who could be added and is not already here —
     * computed server-side so the picker cannot offer somebody the endpoint would refuse.
     *
     * @return array<string, mixed>
     */
    /**
     * Everything the Email Template page needs (P48).
     *
     * @return array<string, mixed>
     */
    private function emailTemplatePayload(HelpCenterSpace $space): array
    {
        $renderer = app(EmailTemplateRenderer::class);
        $me = Auth::user();

        $templates = collect(HelpCenterEmailTemplate::types())
            ->map(function (array $default, string $type) use ($renderer, $space) {
                return $renderer->resolve($space, $type) + [
                    'label' => $default['label'] ?? $type,
                    'description' => $default['description'] ?? null,
                    'can_disable' => (bool) ($default['can_disable'] ?? false),
                    // What "Restore Default" would put back, so the screen can offer it honestly
                    // and show a diff-free comparison without a second round trip.
                    'default_name' => $default['name'] ?? '',
                    'default_subject' => $default['subject'] ?? null,
                    'default_body' => $default['body'] ?? '',
                ];
            })
            ->values()
            ->all();

        /*
         * Signatures, indexed by user id with the Space default under `0`.
         *
         * `0` rather than `null`, because JSON object keys are strings and a null key does not
         * survive the trip — the screen reads `signatures[0]` for the default, which is a
         * choice worth stating rather than leaving somebody to infer from a lookup that works.
         */
        $signatures = HelpCenterSignature::query()
            ->where('help_center_space_id', $space->id)
            ->get()
            ->mapWithKeys(fn (HelpCenterSignature $s) => [(int) ($s->user_id ?? 0) => $s->toPayload()])
            ->all();

        return [
            'description' => 'The emails this Space sends to customers, and how agents sign them.',
            'templates' => $templates,
            'variables' => (array) config('help-center.email_variables'),
            'signatures' => $signatures,
            'me' => (int) $me->id,
            /*
             * Who may be given a signature here.
             *
             * Accepted members only: an invitation that has not been taken up has no user to
             * attach one to, which is the same reason the assignee picker greys them out (P29).
             */
            'agents' => $space->members->filter(fn ($m) => $m->user !== null)
                ->map(fn ($m) => [
                    'id' => $m->user->id,
                    'name' => $m->user->displayName(),
                    'initial' => mb_strtoupper(mb_substr((string) $m->user->displayName(), 0, 1)),
                    'avatar_url' => $m->user->avatar_url ?? null,
                ])->values()->all(),
            'endpoints' => [
                'save' => route('help-center.spaces.email-templates.update', ['space' => $space->id, 'type' => '__TYPE__']),
                'reset' => route('help-center.spaces.email-templates.reset', ['space' => $space->id, 'type' => '__TYPE__']),
                'preview' => route('help-center.spaces.email-templates.preview', ['space' => $space->id, 'type' => '__TYPE__']),
                'test' => route('help-center.spaces.email-templates.test', ['space' => $space->id, 'type' => '__TYPE__']),
                'signatureDefault' => route('help-center.spaces.signatures.default', ['space' => $space->id]),
                'signature' => route('help-center.spaces.signatures.update', ['space' => $space->id, 'user' => '__USER__']),
            ],
            // The rich-text editor's licence, as every other host of it passes it (P41).
            'editorLicense' => (string) config('projects.jodit_license'),
        ];
    }

    private function membersPayload(HelpCenterSpace $space): array
    {
        $roles = collect((array) config('workspace.roles'))
            ->map(fn ($r) => is_array($r) ? ($r['label'] ?? '') : $r)->all();

        $memberships = WorkspaceMembership::query()
            ->where('workspace_id', $space->tenant_id)
            ->pluck('role', 'user_id');

        $space->loadMissing('members.invitation');

        $members = $space->members->map(function ($m) use ($space, $memberships) {
            $user = $m->user;
            $name = $user?->displayName() ?: $m->email;

            return $m->toPayload() + [
                'name' => $name,
                'initial' => mb_strtoupper(mb_substr((string) $name, 0, 1)) ?: '?',
                'email' => $user?->email ?? $m->email,
                'avatar_url' => $user?->avatar_url,
                /*
                 * What they are in the WORKSPACE.
                 *
                 * A Space has no roles of its own: access to a Space is binary, and inventing a
                 * second role vocabulary here would mean two answers to "what may this person
                 * do". A row still pending an invitation shows the role it was invited as.
                 */
                'workspace_role' => $m->user_id === null
                    ? $m->invited_role
                    : ($memberships[$m->user_id] ?? null),
                'is_lead' => $m->user_id !== null && (int) $m->user_id === (int) $space->lead_user_id,
                'added_at' => $m->created_at?->format('M j, Y'),
                /*
                 * "Bounced" is a THIRD state, not a flavour of Invited.
                 *
                 * An invitation whose email was rejected will never be accepted, and showing it
                 * as Invited alongside ones that are genuinely in flight is what let two typo'd
                 * addresses sit unnoticed. Postmark reports this after the send succeeds, so it
                 * arrives via the bounce webhook rather than from sending.
                 */
                'status' => $m->isPending()
                    ? ($m->invitation?->email_status ? 'bounced' : 'invited')
                    : 'active',
                'email_error' => $m->invitation?->email_error,
            ];
        })->values()->all();

        $taken = $space->members->pluck('user_id')->filter()->all();
        $actor = Auth::user();
        $workspace = $actor->currentWorkspace;

        return [
            'members' => $members,
            /*
             * Existing coworkers, offered as suggestions on the email field.
             *
             * The Add Member dialog is an INVITE dialog: it takes an address, so somebody who
             * has never used ProjectBlock can be brought in without leaving this screen. These
             * make the common case — a coworker who is already here — a click rather than a
             * retyped address.
             */
            'candidates' => array_values(array_filter(
                app(EligibleLeads::class)->options($workspace),
                fn (array $c) => ! in_array($c['id'], $taken, false),
            )),
            'roles' => $roles,
            /*
             * Only the roles THIS actor may hand out.
             *
             * WorkspacePolicy::assignRole is the authority; asking it here means the dialog
             * never offers a role the endpoint would refuse, and the refusal is still enforced
             * server-side (HC-D19).
             */
            'inviteRoles' => array_values(array_filter(
                array_map(
                    fn (string $key) => ['value' => $key, 'label' => $roles[$key] ?? $key],
                    (array) config('workspace.invite_roles'),
                ),
                fn (array $r) => $actor->can('assignRole', [$workspace, $r['value']]),
            )),
            'defaultRole' => 'member',
            // The Space's own Department Groups, so the picker offers what this Space defined
            // rather than free text that would never match anything on the Overview.
            'groups' => $space->groupList(),
            'canManage' => Auth::user()->can('update', $space),
            'endpoints' => [
                'store' => route('help-center.spaces.members.store', $space),
                'update' => route('help-center.spaces.members.update', ['space' => $space->id, 'member' => '__ID__']),
                'destroy' => route('help-center.spaces.members.destroy', ['space' => $space->id, 'member' => '__ID__']),
            ],
        ];
    }

    /**
     * The Space's workflow as the panel reads it.
     *
     * One function, used by the page that renders it and by the save that answers it, so a
     * status cannot be described one way on load and another way after an edit.
     *
     * @return array<int, array<string, mixed>>
     */
    private function workflowStatuses(HelpCenterSpace $space): array
    {
        $statuses = $space->statuses()->orderBy('position')->orderBy('id')->get();

        /*
         * Every default assignee named by any status, in ONE query.
         *
         * The Blade panel this replaced ran a `whereIn` per row, inside the loop that rendered
         * them — a workflow with eight statuses was eight queries for a column most of them
         * leave empty.
         */
        $names = User::query()
            ->whereIn('id', $statuses->flatMap(fn ($s) => (array) ($s->default_assignees ?: []))->unique())
            ->pluck('full_name', 'id');

        return $statuses->map(fn (HelpCenterStatus $status) => [
            'id' => $status->id,
            'name' => $status->name,
            'color' => $status->color,
            'is_default' => (bool) $status->is_default,
            'is_active' => (bool) $status->is_active,
            'is_system' => $status->isSystem(),
            'system_key' => $status->system_key,
            // Both forms: the table reads the label, the editor's pickers bind to the value.
            'responsibility' => $status->responsibility,
            'responsibility_label' => $status->responsibility === HelpCenterStatus::RESPONSIBILITY_CREATOR
                ? 'Creator' : 'Assignee',
            'waiting_on' => $status->waiting_on,
            // The System Category (P54) — the raw key, because the panel colours it as well as
            // naming it and needs to look both up.
            'system_category' => $status->system_category,
            'waiting_on_label' => [
                HelpCenterStatus::WAITING_AGENT => 'Agent',
                HelpCenterStatus::WAITING_CUSTOMER => 'Customer',
                HelpCenterStatus::WAITING_NEITHER => 'Nobody',
            ][$status->waiting_on] ?? 'Agent',
            'default_assignees' => array_values(array_map('intval', (array) ($status->default_assignees ?: []))),
            'assignees' => $names->only((array) ($status->default_assignees ?: []))->values()->all(),
        ])->values()->all();
    }

    /**
     * What a metadata section manages BESIDES its switch.
     *
     * Only Tag does, today. It is keyed off the nav entry rather than off the section name so
     * the config stays the single source of what a page contains — the same rule the sections
     * themselves follow.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function managedPayload(HelpCenterSpace $space, array $item): array
    {
        return match ($item['manages'] ?? null) {
            'tags' => [
                'manages' => 'tags',
                'tags' => $space->tags->map->toPayload()->values()->all(),
                'endpointTemplates' => [
                    'tags' => route('help-center.spaces.tags.store', ['space' => $space->id]),
                    'tag' => route('help-center.spaces.tags.destroy', ['space' => $space->id, 'tag' => '__TAG__']),
                ],
            ],

            /*
             * `company_fields` is GONE from here (P75).
             *
             * Company & Customer stopped being a metadata switch with a list bolted on and
             * became its own `kind` — see `companyCustomerPayload()`. Leaving the branch would
             * have left a route into a panel shape nothing renders any more.
             */

            default => [],
        };
    }

    /**
     * Everything the Company & Customer panel draws (P75 §2–§4).
     *
     * The mapping DESTINATIONS carry each Space's own custom fields already merged in, because
     * "Custom fields configured under Company & Customer must also be available as mapping
     * destinations" (§3) — and resolving that on the client would mean the picker and the
     * validator deriving the same list two different ways.
     *
     * @return array<string, mixed>
     */
    private function companyCustomerPayload(HelpCenterSpace $space): array
    {
        $features = [];

        foreach (array_merge(['company'], HelpCenterSpace::COMPANY_SUB_FEATURES) as $key) {
            $meta = (array) config('help-center.metadata.'.$key);

            $features[] = [
                'key' => $key,
                'label' => (string) ($meta['label'] ?? $key),
                'help' => (string) ($meta['help'] ?? ''),
                'available' => (bool) ($meta['available'] ?? false),
                // Read off the settings row directly rather than through `featureEnabled()`:
                // the panel needs each switch's OWN stored state so the master can grey the
                // others out without also reporting them as off.
                'enabled' => $space->settings === null
                    ? (bool) ($meta['default'] ?? false)
                    : $space->settings->feature($key),
                // The master governs the rest; the panel disables them while it is off.
                'master' => $key === 'company',
            ];
        }

        $customerFields = $space->customerFields->map->toPayload()->values()->all();
        $companyFields = $space->companyFields->map->toPayload()->values()->all();

        return [
            'description' => 'Track the customers who write in, the companies they belong to, '
                .'and how an incoming request maps onto both.',
            'features' => $features,
            'customerFields' => $customerFields,
            'companyFields' => $companyFields,
            'fieldTypes' => HelpCenterCompanyField::types(),
            'optionMax' => (int) config('help-center.company_field_option_max', 50),
            'optionMaxLength' => (int) config('help-center.company_field_option_max_length', 100),

            'mappings' => $space->metadataMappings->map->toPayload()->values()->all(),
            'mappingSources' => HelpCenterMetadataMapping::sources(),
            'mappingRecordTypes' => collect(HelpCenterMetadataMapping::destinations())
                ->map(fn (array $d, string $key) => [
                    'key' => $key,
                    'label' => (string) $d['label'],
                    'fields' => $this->destinationFields($key, $key === 'company' ? $companyFields : $customerFields),
                ])
                ->values()->all(),
            'mappingMax' => (int) config('help-center.mapping_max', 60),
            'hasMappings' => $space->metadataMappings->isNotEmpty(),

            'endpointTemplates' => [
                // `__KIND__` and `__FIELD__` are replaced on the client, the same placeholder
                // convention the tag and template panels already use.
                'customFields' => route('help-center.spaces.custom-fields.store', [
                    'space' => $space->id, 'kind' => '__KIND__',
                ]),
                'customField' => route('help-center.spaces.custom-fields.update', [
                    'space' => $space->id, 'kind' => '__KIND__', 'field' => '__FIELD__',
                ]),
                'mappings' => route('help-center.spaces.metadata-mappings.store', ['space' => $space->id]),
                'mapping' => route('help-center.spaces.metadata-mappings.update', [
                    'space' => $space->id, 'mapping' => '__MAPPING__',
                ]),
                'seed' => route('help-center.spaces.metadata-mappings.seed', ['space' => $space->id]),
                'reprocess' => route('help-center.spaces.metadata-mappings.reprocess', ['space' => $space->id]),
            ],
        ];
    }

    /**
     * One record type's destination list, with its Space's custom fields spliced in.
     *
     * The config's `custom_field` entry is REPLACED by one option per active field rather than
     * kept alongside them: "Custom Field" as a choice would need a second dropdown to say which,
     * and a flat list is one decision instead of two. A Space with no fields simply has fewer
     * options, which is honest.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    private function destinationFields(string $recordType, array $fields): array
    {
        $out = [];

        foreach (HelpCenterMetadataMapping::fieldsFor($recordType) as $field) {
            if (! ($field['custom'] ?? false)) {
                $out[] = ['key' => $field['key'], 'label' => $field['label'], 'custom_field_id' => null];

                continue;
            }

            foreach ($fields as $custom) {
                if (! ($custom['is_active'] ?? true)) {
                    continue;
                }

                $out[] = [
                    'key' => HelpCenterMetadataMapping::DESTINATION_CUSTOM_FIELD,
                    'label' => $custom['name'].' (custom field)',
                    'custom_field_id' => $custom['id'],
                ];
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function metadataPayload(?HelpCenterSpaceSettings $cfg, string $key): array
    {
        $meta = (array) config("help-center.metadata.$key");
        $stored = (array) ($cfg?->metadata ?? []);

        return [
            'description' => (string) ($meta['help'] ?? ''),
            'toggleLabel' => (string) ($meta['label'] ?? $key),
            // Falls back to the DEFAULT rather than to false: a Space with no settings row is
            // running on the defaults, and showing every switch off would misdescribe it.
            'enabled' => (bool) ($stored[$key] ?? ($meta['default'] ?? false)),
            'available' => (bool) ($meta['available'] ?? false),
        ];
    }

    /**
     * The sub-navigation, with each item's URL resolved.
     *
     * @return array<int, array<string, mixed>>
     */
    private function navFor(HelpCenterSpace $space): array
    {
        return collect((array) config('help-center.space_settings_nav'))
            ->map(fn (array $item) => $item + [
                'url' => ($item['status'] ?? 'active') === 'soon'
                    ? null
                    : route('help-center.spaces.settings', ['space' => $space->id, 'setting' => $item['key']]),
            ])
            ->all();
    }

    /** Where Settings opens — the first configured section. */
    public static function firstSection(): string
    {
        return (string) (((array) config('help-center.space_settings_nav'))[0]['key'] ?? 'inbox');
    }

    /** @return array<string, mixed>|null */
    private static function item(string $key): ?array
    {
        return collect((array) config('help-center.space_settings_nav'))->firstWhere('key', $key);
    }
}
