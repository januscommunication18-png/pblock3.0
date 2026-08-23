<?php

namespace App\Services\HelpCenter;

use App\Models\HelpCenterEmailTemplate;
use App\Models\HelpCenterRequest;
use App\Models\HelpCenterSpace;
use App\Models\User;

/**
 * Turning a Space's template into an actual email (docs/features/help-center.md, P48).
 *
 * Two jobs, and they are deliberately the same code for a real send, a preview and a test email —
 * the requirement asks that administrators can preview before saving and send tests, and a
 * preview produced by different code from the send is a preview of nothing.
 *
 *   resolve()  — which template applies: the Space's override, or the packaged default
 *   render()   — substitute the merge tags
 */
class EmailTemplateRenderer
{
    public function __construct(private readonly SignatureResolver $signatures) {}

    /**
     * The template in force for a Space and type.
     *
     * Returns a plain array rather than a model, because a Space that has never touched its
     * templates has no model — and every caller wants the same four fields either way. Callers
     * that need to know whether it is stored read `custom`.
     *
     * @return array{type: string, name: string, subject: ?string, body: string, enabled: bool, custom: bool}
     */
    public function resolve(HelpCenterSpace $space, string $type): array
    {
        $default = HelpCenterEmailTemplate::default($type);

        $row = HelpCenterEmailTemplate::query()
            ->where('help_center_space_id', $space->id)
            ->where('type', $type)
            ->first();

        return [
            'type' => $type,
            'name' => (string) ($row->name ?? $default['name'] ?? $type),
            'subject' => $row->subject ?? ($default['subject'] ?? null),
            'body' => (string) ($row->body ?? $default['body'] ?? ''),
            /*
             * A type that cannot be disabled is ALWAYS enabled, whatever the column says.
             *
             * Answered here rather than at each send site, so "the agent reply template remains
             * available whenever an agent replies" is a property of the template rather than a
             * rule four callers have to remember.
             */
            'enabled' => HelpCenterEmailTemplate::canDisable($type)
                ? (bool) ($row->enabled ?? true)
                : true,
            'custom' => $row !== null,
        ];
    }

    /**
     * Substitute `{{tag}}` throughout a string.
     *
     * Values are ESCAPED unless the variable is declared `html` in config. That list is short and
     * every entry on it is markup this application sanitized on the way in: the agent's reply
     * (P41), the signature (built from escaped fields plus sanitized content), and the rendered
     * inner email when the layout wraps it.
     *
     * Everything else is text from an email header or a database column that a stranger chose —
     * a customer whose display name is `<script>` is a customer, not a script.
     *
     * An unknown tag is left EXACTLY as it was written rather than blanked. Somebody who typos
     * `{{ticket_num}}` should see it in the preview and fix it; silently emptying it would send
     * a customer an email with a hole in it and no clue where the hole came from.
     */
    public function render(string $template, array $values): string
    {
        $html = $this->htmlTags();

        return (string) preg_replace_callback(
            '/\{\{\s*([a-z_]+)\s*\}\}/i',
            function (array $m) use ($values, $html) {
                $tag = strtolower($m[1]);

                if (! array_key_exists($tag, $values)) {
                    return $m[0];
                }

                $value = (string) $values[$tag];

                return in_array($tag, $html, true) ? $value : e($value);
            },
            $template,
        );
    }

    /**
     * Every value a ticket email can carry.
     *
     * Built in one place so the preview, the test and the real send cannot disagree about what
     * `{{support_email}}` means.
     *
     * @param  string|null  $replyContent  sanitized HTML — the agent's own words, for `agent_reply`
     * @return array<string, string>
     */
    public function variables(
        HelpCenterRequest $request,
        HelpCenterSpace $space,
        ?User $agent = null,
        ?string $replyContent = null,
        ?string $supportEmail = null,
        ?string $ratingLink = null,
        /*
         * Does `$replyContent` ALREADY carry the signature? (P74)
         *
         * Since P74 the reply composer is pre-filled with the resolved signature so the agent can
         * review and edit it — which means it arrives inside `reply_content`. Substituting
         * `{{agent_signature}}` as well would send the customer two copies, one of them the
         * version the agent had just edited and one of them not.
         *
         * True for a real send from the composer; false for the PREVIEW and the TEST email,
         * where there is no composer and the tag is the only thing that can show an
         * administrator where a signature lands.
         */
        bool $signatureInReply = false,
    ): array {
        return [
            'customer_name' => (string) $request->customerLabel(),
            'customer_email' => (string) $request->customer_email,
            'ticket_number' => $request->ticketNumber(),
            'ticket_subject' => (string) ($request->subject ?: '(no subject)'),
            'agent_name' => (string) ($agent?->displayName() ?? ''),
            'agent_signature' => $signatureInReply ? '' : $this->signatures->html($space, $agent),
            'reply_content' => (string) ($replyContent ?? ''),
            // The customer's rating link (P56). Empty everywhere but the rating template, and
            // the tag is only offered there.
            'rating_link' => (string) ($ratingLink ?? ''),
            'space_name' => (string) $space->name,
            'support_email' => (string) ($supportEmail ?? ''),
        ];
    }

    /**
     * Render a template and wrap it in the Space's layout, ready to send.
     *
     * The layout is the outer of two renders: the inner template's output goes into the layout's
     * `{{email_content}}`, and the layout sees the same variables so its footer can carry the
     * ticket number. A Space that has disabled its layout gets the inner render alone.
     *
     * @return array{subject: string, html: string}
     */
    public function compose(HelpCenterSpace $space, string $type, array $values): array
    {
        $template = $this->resolve($space, $type);

        $inner = $this->render($template['body'], $values);
        $layout = $this->resolve($space, HelpCenterEmailTemplate::TYPE_TICKET_LAYOUT);

        $html = $layout['enabled'] && $type !== HelpCenterEmailTemplate::TYPE_TICKET_LAYOUT
            ? $this->render($layout['body'], $values + ['email_content' => $inner])
            : $inner;

        return [
            'subject' => trim($this->render((string) ($template['subject'] ?? ''), $values)),
            'html' => $html,
        ];
    }

    /** The tags whose values are markup and must not be escaped. */
    private function htmlTags(): array
    {
        return collect((array) config('help-center.email_variables'))
            ->filter(fn (array $v) => (bool) ($v['html'] ?? false))
            ->pluck('tag')
            ->all();
    }
}
