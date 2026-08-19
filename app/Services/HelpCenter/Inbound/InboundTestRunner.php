<?php

namespace App\Services\HelpCenter\Inbound;

use App\Mail\InboundTestMail;
use App\Models\HelpCenterInbox;
use App\Models\HelpCenterInboundTest;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

/**
 * Starting and resolving an end-to-end inbound test (docs/features/help-center.md, P7).
 *
 * The rule the whole feature turns on:
 *
 *   **Sending the test email successfully does NOT mean inbound works.**
 *
 * A test only passes when the token comes back through the customer's own forwarding rule,
 * Postmark, our webhook and our parser. So `send()` never sets `passed` — the most it can
 * report is `sent`, and only InboundIngestor completing the round trip promotes it.
 */
class InboundTestRunner
{
    /** Same alphabet as the inbound identifier: no vowels, no look-alikes. */
    private const ALPHABET = '0123456789abcdefghjkmnpqrstvwxyz';

    /**
     * Send a probe and return the test row.
     *
     * @throws RuntimeException when the Inbox has no address to test through
     */
    public function start(HelpCenterInbox $inbox, User $actor): HelpCenterInboundTest
    {
        /*
         * The probe goes to the CUSTOMER-FACING address, not to our inbound address.
         *
         * Sending straight to the inbound address would prove only that Postmark can receive
         * mail from us — it would skip the customer's mailbox and their forwarding rule, which
         * are the two things most likely to be misconfigured and the two things this test
         * exists to exercise.
         */
        $address = $inbox->emailAddresses()->orderBy('id')->first();

        if ($address === null) {
            throw new RuntimeException(
                'This Inbox has no customer-facing email address, so there is nothing to forward from. '
                .'Add the address your customers write to, then run the test.'
            );
        }

        $test = HelpCenterInboundTest::create([
            'tenant_id' => $inbox->tenant_id,
            'help_center_space_id' => $inbox->help_center_space_id,
            'help_center_inbox_id' => $inbox->id,
            'started_by' => $actor->id,
            'test_token' => $this->token(),
            'test_email_address' => $address->email,
            'inbound_email_address' => $inbox->inboundAddress(),
            'status' => HelpCenterInboundTest::STATUS_PENDING,
        ]);

        try {
            Mail::to($test->test_email_address)->send(new InboundTestMail($test));
        } catch (Throwable $e) {
            /*
             * Outbound refused. That is a distinct failure from "not forwarded" and the card
             * says so, because the remedy is completely different — this one is ours to fix.
             */
            $test->forceFill([
                'status' => HelpCenterInboundTest::STATUS_SEND_FAILED,
                'failed_at' => now(),
                'failure_reason' => $e->getMessage(),
            ])->save();

            Log::error('help-center.inbound_test.send_failed', [
                'test_id' => $test->id,
                'to' => $test->test_email_address,
                'error' => $e->getMessage(),
            ]);

            return $test;
        }

        // `sent` and no further. Only the round trip can promote this.
        $test->forceFill([
            'status' => HelpCenterInboundTest::STATUS_SENT,
            'sent_at' => now(),
        ])->save();

        Log::info('help-center.inbound_test.sent', [
            'test_id' => $test->id,
            'token' => $test->test_token,
            'to' => $test->test_email_address,
        ]);

        return $test;
    }

    /**
     * The test a returning message belongs to, if any.
     *
     * Looked up WITHOUT the tenant scope: an inbound webhook has no tenancy context, and the
     * token identifies the test on its own — exactly like the Inbox's `inbound_id`.
     */
    public function match(PostmarkPayload $payload): ?HelpCenterInboundTest
    {
        $token = $this->tokenIn($payload);

        if ($token === null) {
            return null;
        }

        return HelpCenterInboundTest::query()
            ->withoutGlobalScopes()
            ->where('test_token', $token)
            ->first();
    }

    /** Mark a matched test as having completed the whole chain. */
    public function pass(HelpCenterInboundTest $test, PostmarkPayload $payload): void
    {
        // A retried webhook must not rewrite a finished test's timestamps.
        if ($test->hasPassed()) {
            return;
        }

        $now = now();

        $test->forceFill([
            'status' => HelpCenterInboundTest::STATUS_PASSED,
            'received_at' => $now,
            // Received and parsed are the same instant here: finding the token IS the parse.
            'parsed_at' => $now,
            'postmark_inbound_message_id' => $payload->providerMessageId(),
            'received_meta' => [
                'from' => $payload->fromEmail(),
                'to' => implode(', ', $payload->toRecipients()),
                'subject' => $payload->subject(),
                'inbound_id' => $test->inbound_email_address,
                'processing' => 'Parsed successfully',
            ],
        ])->save();

        Log::info('help-center.inbound_test.passed', [
            'test_id' => $test->id,
            'token' => $test->test_token,
        ]);
    }

    /**
     * The token carried by a returning message.
     *
     * Subject first — the one part that reliably survives a forwarding rule. The header is a
     * fallback for providers that preserve it, and the body a last resort for the ones that
     * rewrite the subject with a `Fwd:` prefix and their own wrapper.
     */
    public function tokenIn(PostmarkPayload $payload): ?string
    {
        $haystacks = [
            (string) $payload->subject(),
            (string) $payload->textBody(),
            (string) $payload->htmlBody(),
        ];

        foreach ($haystacks as $haystack) {
            if (preg_match('/PB-TEST-([0-9a-z]{12})/i', $haystack, $m)) {
                return strtolower($m[1]);
            }

            // The body prints the bare token, without the PB-TEST- wrapper.
            if (preg_match('/\b([0-9a-hjkmnp-tv-z]{12})\b/', $haystack, $m)
                && HelpCenterInboundTest::query()->withoutGlobalScopes()
                    ->where('test_token', strtolower($m[1]))->exists()) {
                return strtolower($m[1]);
            }
        }

        return null;
    }

    private function token(): string
    {
        $out = '';

        for ($i = 0; $i < 12; $i++) {
            $out .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $out;
    }
}
