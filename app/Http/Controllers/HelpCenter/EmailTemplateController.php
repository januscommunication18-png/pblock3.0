<?php

namespace App\Http\Controllers\HelpCenter;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HelpCenter\Concerns\GuardsHelpCenter;
use App\Mail\HelpCenterTicketConfirmationMail;
use App\Models\HelpCenterEmailAddress;
use App\Models\HelpCenterEmailTemplate;
use App\Models\HelpCenterRequest;
use App\Models\HelpCenterSignature;
use App\Models\HelpCenterSpace;
use App\Services\HelpCenter\EmailTemplateRenderer;
use App\Services\HelpCenter\SignatureResolver;
use App\Services\HelpCenter\SpaceSender;
use App\Services\RichTextSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * A Space's email templates and signatures (docs/features/help-center.md, P48).
 *
 * Its own controller rather than more branches on `SpaceSettingsController::update()`, because
 * this section is not a settings switch: it has four verbs the others do not have (preview, test,
 * reset, and a second resource in signatures) and it writes to two tables of its own.
 *
 * Everything here is gated on managing the SPACE, with one deliberate exception — an agent may
 * save their OWN signature in a Space they merely work in. See `saveSignature()`.
 */
class EmailTemplateController extends Controller
{
    use GuardsHelpCenter;

    public function __construct(
        private readonly EmailTemplateRenderer $templates,
        private readonly SignatureResolver $signatures,
        private readonly RichTextSanitizer $richText,
    ) {}

    /** PUT /help-center/spaces/{space}/email-templates/{type} */
    public function update(Request $request, HelpCenterSpace $space, string $type): JsonResponse
    {
        $this->manageable($space);
        abort_unless(HelpCenterEmailTemplate::isType($type), 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:60000'],
            'enabled' => ['sometimes', 'boolean'],
        ]);

        $body = (string) $this->richText->sanitize($data['body']);

        if (trim(strip_tags($body)) === '' && ! str_contains($body, '{{')) {
            return response()->json(['ok' => false, 'message' => 'The template body cannot be empty.'], 422);
        }

        /*
         * A template that drops the thing it exists to carry is refused.
         *
         * The agent reply without `{{reply_content}}` sends the customer a wrapper and none of
         * the answer; the layout without `{{email_content}}` REPLACES every email instead of
         * framing it. Both are saveable-looking mistakes whose damage is invisible until a
         * customer receives one, which is exactly the kind of thing a save should catch.
         */
        $required = match ($type) {
            HelpCenterEmailTemplate::TYPE_AGENT_REPLY => 'reply_content',
            HelpCenterEmailTemplate::TYPE_TICKET_LAYOUT => 'email_content',
            default => null,
        };

        if ($required !== null && ! preg_match('/\{\{\s*'.$required.'\s*\}\}/i', $body)) {
            return response()->json([
                'ok' => false,
                'message' => 'This template must include {{'.$required.'}} — without it the email would go out empty.',
            ], 422);
        }

        $row = HelpCenterEmailTemplate::updateOrCreate(
            ['help_center_space_id' => $space->id, 'type' => $type],
            [
                'tenant_id' => $space->tenant_id,
                'name' => $data['name'],
                // The layout has no subject of its own; anything sent for one is discarded.
                'subject' => $type === HelpCenterEmailTemplate::TYPE_TICKET_LAYOUT ? null : ($data['subject'] ?? null),
                'body' => $body,
                'enabled' => HelpCenterEmailTemplate::canDisable($type) ? (bool) ($data['enabled'] ?? true) : true,
            ],
        );

        return response()->json([
            'ok' => true,
            'template' => $this->templates->resolve($space, $row->type),
            'message' => 'Template saved.',
        ]);
    }

    /**
     * DELETE …/email-templates/{type} — "Restore Default Template".
     *
     * A delete, not a copy of the default text into the row. The absence of a row IS the default
     * (see the migration), so this both restores today's wording and re-subscribes the Space to
     * any future improvement of it.
     */
    public function reset(HelpCenterSpace $space, string $type): JsonResponse
    {
        $this->manageable($space);
        abort_unless(HelpCenterEmailTemplate::isType($type), 404);

        HelpCenterEmailTemplate::query()
            ->where('help_center_space_id', $space->id)
            ->where('type', $type)
            ->delete();

        return response()->json([
            'ok' => true,
            'template' => $this->templates->resolve($space, $type),
            'message' => 'Default template restored.',
        ]);
    }

    /**
     * POST …/email-templates/{type}/preview
     *
     * Renders what is IN THE EDITOR, not what is saved — the requirement asks that an
     * administrator can "preview templates before saving", and previewing the stored row would
     * answer a question nobody asked.
     */
    public function preview(Request $request, HelpCenterSpace $space, string $type): JsonResponse
    {
        $this->manageable($space);
        abort_unless(HelpCenterEmailTemplate::isType($type), 404);

        $data = $request->validate([
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:60000'],
        ]);

        $values = $this->sampleValues($space);
        $body = (string) $this->richText->sanitize($data['body']);

        /*
         * The LAYOUT is applied around the preview too, unless the layout is what is being
         * previewed — the customer sees the composed email, so an administrator previewing an
         * auto-response without its own footer would be shown something that never gets sent.
         */
        $inner = $this->templates->render($body, $values);
        $layout = $this->templates->resolve($space, HelpCenterEmailTemplate::TYPE_TICKET_LAYOUT);

        $html = $layout['enabled'] && $type !== HelpCenterEmailTemplate::TYPE_TICKET_LAYOUT
            ? $this->templates->render($layout['body'], $values + ['email_content' => $inner])
            : $inner;

        return response()->json([
            'ok' => true,
            'subject' => trim($this->templates->render((string) ($data['subject'] ?? ''), $values)),
            'html' => $html,
            // Named so the screen can say whose details these are rather than implying a real one.
            'sample' => true,
        ]);
    }

    /**
     * POST …/email-templates/{type}/test — Send Test Email.
     *
     * To the signed-in administrator, and NEVER to an address they type. A settings form that
     * will send mail anywhere somebody enters is an open relay with a nice interface; the person
     * asking to see the email is the person sitting there, and their own address is on file.
     */
    public function test(Request $request, HelpCenterSpace $space, string $type): JsonResponse
    {
        $this->manageable($space);
        abort_unless(HelpCenterEmailTemplate::isType($type), 404);

        $data = $request->validate([
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:60000'],
        ]);

        $to = trim((string) Auth::user()->email);

        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['ok' => false, 'message' => 'Your account has no valid email address.'], 422);
        }

        $values = $this->sampleValues($space);
        $body = (string) $this->richText->sanitize($data['body']);

        $inner = $this->templates->render($body, $values);
        $layout = $this->templates->resolve($space, HelpCenterEmailTemplate::TYPE_TICKET_LAYOUT);

        $html = $layout['enabled'] && $type !== HelpCenterEmailTemplate::TYPE_TICKET_LAYOUT
            ? $this->templates->render($layout['body'], $values + ['email_content' => $inner])
            : $inner;

        $subject = trim($this->templates->render((string) ($data['subject'] ?? ''), $values))
            ?: 'Test email from '.$space->name;

        try {
            /*
             * A REAL ticket is not involved, so a throwaway unsaved model carries the sample
             * ticket number for the Mailable's fallback subject. It is never persisted.
             */
            $stub = new HelpCenterRequest(['subject' => $values['ticket_subject']]);
            $stub->help_center_space_id = $space->id;

            /*
             * Sent AS THE SPACE (P65), like the real thing.
             *
             * The whole point of a test send is to see what the customer will see, and a test
             * that arrives from a different sender than the live mail is a test of something
             * else. `forSpace()` returns nulls for a Space with no Inbox, which falls back to
             * the global sender rather than refusing — a template preview is worth sending even
             * from a Space whose channel is not configured yet.
             */
            $identity = app(SpaceSender::class)->forSpace($space);

            Mail::to($to)->send(new HelpCenterTicketConfirmationMail(
                $stub,
                $identity['reply_to'],
                null,
                '[TEST] '.$subject,
                $html,
                $identity['from_email'],
                $identity['from_name'],
                $identity['reply_to_name'],
            ));
        } catch (Throwable $e) {
            Log::error('help-center.template.test_failed', [
                'space_id' => $space->id,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'ok' => false,
                'message' => 'The test email could not be sent — '.$e->getMessage(),
            ], 422);
        }

        return response()->json(['ok' => true, 'message' => 'Test email sent to '.$to.'.']);
    }

    /**
     * PUT …/signatures/{user?} — an agent's signature, or the Space default when `user` is absent.
     *
     * The permission split is the one place this controller is not simply "can manage the Space":
     *
     *   the Space DEFAULT signature is administration — managing the Space,
     *   an agent's OWN signature is theirs — being a member is enough,
     *   somebody ELSE'S signature is administration again.
     *
     * Writing in somebody else's name is the thing being guarded against, and "can manage" is
     * the wrong test for the middle case: an agent who cannot administer the Space still has to
     * be able to sign their own replies, which is the whole point of the feature.
     */
    public function saveSignature(Request $request, HelpCenterSpace $space, ?int $user = null): JsonResponse
    {
        $this->helpCenterWorkspace();

        $me = Auth::user();
        $canManage = $me->can('update', $space);
        $isOwn = $user !== null && (int) $user === (int) $me->id;

        abort_unless($canManage || $isOwn, 403);

        // Not a member, not a signature: somebody who cannot open the Space cannot sign for it.
        if ($user !== null && ! $space->members()->where('user_id', $user)->exists()) {
            return response()->json(['ok' => false, 'message' => 'That person is not a member of this Space.'], 422);
        }

        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'name' => ['nullable', 'string', 'max:120'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'company' => ['nullable', 'string', 'max:120'],
            'avatar_url' => ['nullable', 'url', 'max:2048'],
            'content' => ['nullable', 'string', 'max:20000'],
        ]);

        $row = $this->signatures->save($space, $user, [
            'enabled' => (bool) ($data['enabled'] ?? true),
            'name' => $data['name'] ?? null,
            'job_title' => $data['job_title'] ?? null,
            'company' => $data['company'] ?? null,
            'avatar_url' => $data['avatar_url'] ?? null,
            'content' => array_key_exists('content', $data)
                ? (string) $this->richText->sanitize((string) $data['content'])
                : null,
        ]);

        return response()->json([
            'ok' => true,
            'signature' => $row->fresh()->toPayload(),
            'message' => $user === null ? 'Space default signature saved.' : 'Signature saved.',
        ]);
    }

    /** DELETE …/signatures/{user?} — remove it, so the next one down the priority order applies. */
    public function deleteSignature(HelpCenterSpace $space, ?int $user = null): JsonResponse
    {
        $this->helpCenterWorkspace();

        $me = Auth::user();
        abort_unless($me->can('update', $space) || ($user !== null && (int) $user === (int) $me->id), 403);

        HelpCenterSignature::query()
            ->where('help_center_space_id', $space->id)
            ->when($user === null, fn ($q) => $q->whereNull('user_id'))
            ->when($user !== null, fn ($q) => $q->where('user_id', $user))
            ->delete();

        return response()->json(['ok' => true, 'message' => 'Signature removed.']);
    }

    /** Managing the Space — the gate everything but an agent's own signature is behind. */
    private function manageable(HelpCenterSpace $space): void
    {
        $this->helpCenterWorkspace();
        abort_unless(Auth::user()->can('update', $space), 403);
    }

    /**
     * Stand-in values for a preview or a test.
     *
     * Obviously fictional on purpose — "Sample Customer", not a real name pulled from the Space's
     * most recent ticket. An administrator checking a template should not be shown a customer's
     * actual details, and a preview that quietly reads live data is a small privacy leak in a
     * settings screen.
     *
     * The signature is REAL, resolved for the person previewing: it is the one value where the
     * question "what will this look like?" is genuinely about their own configuration.
     *
     * @return array<string, string>
     */
    private function sampleValues(HelpCenterSpace $space): array
    {
        $me = Auth::user();

        return [
            'customer_name' => 'Sample Customer',
            'customer_email' => 'customer@example.com',
            'ticket_number' => '#000123',
            'ticket_subject' => 'Example support request',
            'agent_name' => (string) $me->displayName(),
            'agent_signature' => $this->signatures->html($space, $me),
            'reply_content' => '<p>This is where the reply an agent writes will appear.</p>',
            'space_name' => (string) $space->name,
            'support_email' => (string) ($this->supportAddress($space) ?? 'support@example.com'),
        ];
    }

    /** The Space's own customer-facing address, preferring a verified one. */
    private function supportAddress(HelpCenterSpace $space): ?string
    {
        $addresses = $space->inboxes->first()?->emailAddresses;

        if ($addresses === null || $addresses->isEmpty()) {
            return null;
        }

        return ($addresses->firstWhere('status', HelpCenterEmailAddress::STATUS_VERIFIED) ?? $addresses->first())?->email;
    }
}
