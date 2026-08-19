<?php

namespace App\Services\HelpCenter\Inbound;

use App\Models\HelpCenterConversation;
use App\Models\HelpCenterEmailAddress;
use App\Models\HelpCenterInbox;
use App\Models\HelpCenterMessage;
use App\Models\HelpCenterStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Turning a parsed inbound email into a conversation (docs/features/help-center.md, P6).
 *
 * Three things happen here, in one transaction: the message finds or opens its conversation,
 * the message is stored, and the customer-facing address it was forwarded from is marked
 * Verified — which is what finally makes §11's "Verified" status reachable, and closes out
 * HC-D7's deferral.
 *
 * IDEMPOTENT by construction. Postmark retries any webhook that does not answer 2xx, so the
 * same Message-ID can arrive several times; the unique key on `(tenant_id, message_id)` and the
 * check below mean a retry is a no-op rather than a duplicate reply in somebody's thread.
 */
class InboundIngestor
{
    /**
     * Store one message. Returns the conversation it landed in, or null if it was a duplicate.
     */
    public function ingest(HelpCenterInbox $inbox, PostmarkPayload $payload): ?HelpCenterConversation
    {
        $tenantId = $inbox->tenant_id;
        $messageId = $payload->messageId();

        // Seen already — Postmark retrying, or the same mail reaching two of our addresses.
        if ($messageId !== null && $this->alreadyStored($tenantId, $messageId)) {
            Log::info('help-center.inbound.duplicate', [
                'inbox_id' => $inbox->id,
                'message_id' => $messageId,
            ]);

            return null;
        }

        return DB::transaction(function () use ($inbox, $payload, $tenantId, $messageId) {
            $conversation = $this->conversationFor($inbox, $payload, $tenantId);

            HelpCenterMessage::create([
                'tenant_id' => $tenantId,
                'help_center_conversation_id' => $conversation->id,
                'direction' => HelpCenterMessage::DIRECTION_INBOUND,
                'from_email' => $payload->fromEmail(),
                'from_name' => $payload->fromName(),
                // Kept whole for reply handling (§20 rule 10).
                'to_recipients' => $payload->toRecipients(),
                'cc_recipients' => $payload->ccRecipients(),
                'subject' => $payload->subject(),
                'body_text' => $payload->textBody(),
                'body_html' => $payload->htmlBody(),
                'message_id' => $messageId,
                'provider_message_id' => $payload->providerMessageId(),
                'received_at' => $payload->receivedAt(),
            ]);

            /*
             * A reply reopens its conversation.
             *
             * A customer answering a closed thread is not opening a new problem; they are
             * continuing the old one, and leaving it closed would hide their reply from every
             * view except Closed.
             */
            $conversation->forceFill([
                'last_message_at' => $payload->receivedAt(),
                'closed_at' => null,
            ])->save();

            $this->markAddressesVerified($inbox, $payload);

            return $conversation;
        });
    }

    /**
     * The thread this message belongs to — found by reply headers, or newly opened.
     *
     * Matching on `In-Reply-To`/`References` rather than on the subject line: "Re: Invoice"
     * from two different customers is two problems, and a subject match would merge them.
     */
    private function conversationFor(
        HelpCenterInbox $inbox,
        PostmarkPayload $payload,
        string $tenantId,
    ): HelpCenterConversation {
        $replyIds = $payload->replyToIds();

        if ($replyIds !== []) {
            $existing = HelpCenterConversation::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('help_center_inbox_id', $inbox->id)
                ->whereIn('thread_key', $replyIds)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            // Not the root, but a message we already hold — join through its conversation.
            $sibling = HelpCenterMessage::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereIn('message_id', $replyIds)
                ->first();

            if ($sibling !== null) {
                return $sibling->conversation;
            }
        }

        return HelpCenterConversation::create([
            'tenant_id' => $tenantId,
            'help_center_space_id' => $inbox->help_center_space_id,
            'help_center_inbox_id' => $inbox->id,
            // Opens in its Space's own Open status (P3 §17: statuses are per Space).
            'help_center_status_id' => $this->openStatusId($inbox),
            'subject' => $payload->subject(),
            'customer_email' => $payload->fromEmail(),
            'customer_name' => $payload->fromName(),
            // The root of the thread, so later replies can find it.
            'thread_key' => $payload->messageId(),
            'last_message_at' => $payload->receivedAt(),
            'is_spam' => $payload->isSpam(),
        ]);
    }

    private function openStatusId(HelpCenterInbox $inbox): ?int
    {
        return HelpCenterStatus::query()
            ->withoutGlobalScopes()
            ->where('help_center_space_id', $inbox->help_center_space_id)
            ->where('system_key', HelpCenterStatus::SYSTEM_OPEN)
            ->value('id');
    }

    /**
     * Mark the address this mail was forwarded from as Verified (§11).
     *
     * This is the moment §11 was waiting for: receiving a forwarded message IS the proof that
     * the forwarding works, so no test-email button is needed to establish it. Only addresses
     * actually present on the message are touched — an Inbox with three connected addresses
     * verifies the one that delivered, not all three.
     */
    private function markAddressesVerified(HelpCenterInbox $inbox, PostmarkPayload $payload): void
    {
        $seen = array_map(
            'mb_strtolower',
            array_merge($payload->toRecipients(), $payload->ccRecipients()),
        );

        if ($seen === []) {
            return;
        }

        HelpCenterEmailAddress::query()
            ->withoutGlobalScopes()
            ->where('help_center_inbox_id', $inbox->id)
            ->whereIn('email', $seen)
            ->where('status', '!=', HelpCenterEmailAddress::STATUS_VERIFIED)
            ->update([
                'status' => HelpCenterEmailAddress::STATUS_VERIFIED,
                'verified_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function alreadyStored(string $tenantId, string $messageId): bool
    {
        return HelpCenterMessage::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('message_id', $messageId)
            ->exists();
    }
}
