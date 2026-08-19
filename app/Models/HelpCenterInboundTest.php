<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * One end-to-end inbound test (docs/features/help-center.md, P7). TENANT-SCOPED.
 *
 * The statuses are the legs of the chain, and the failures name WHICH leg broke — that is the
 * whole product requirement: "Failed test explains whether the issue occurred during sending,
 * forwarding, receiving, or parsing."
 */
class HelpCenterInboundTest extends Model
{
    use BelongsToTenant;

    /** Row created, outbound not yet attempted. */
    public const STATUS_PENDING = 'pending';

    /** We handed it to Postmark. Says nothing about forwarding yet. */
    public const STATUS_SENT = 'sent';

    /** The whole chain completed and the parser found the token. */
    public const STATUS_PASSED = 'passed';

    /** Outbound never left — Postmark refused it. */
    public const STATUS_SEND_FAILED = 'send_failed';

    /** Sent, but nothing came back before the timeout: the forwarding rule is the suspect. */
    public const STATUS_TIMEOUT = 'timeout';

    /** It came back, but could not be processed. */
    public const STATUS_PARSE_FAILED = 'parse_failed';

    protected $attributes = [
        'status' => self::STATUS_PENDING,
    ];

    protected $fillable = [
        'tenant_id',
        'help_center_space_id',
        'help_center_inbox_id',
        'started_by',
        'test_token',
        'test_email_address',
        'inbound_email_address',
        'status',
        'outbound_message_id',
        'postmark_inbound_message_id',
        'sent_at',
        'received_at',
        'parsed_at',
        'failed_at',
        'failure_reason',
        'received_meta',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'received_at' => 'datetime',
            'parsed_at' => 'datetime',
            'failed_at' => 'datetime',
            'received_meta' => 'array',
        ];
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(HelpCenterSpace::class, 'help_center_space_id');
    }

    public function inbox(): BelongsTo
    {
        return $this->belongsTo(HelpCenterInbox::class, 'help_center_inbox_id');
    }

    /** Still waiting on the round trip. */
    public function isRunning(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_SENT], true);
    }

    public function hasPassed(): bool
    {
        return $this->status === self::STATUS_PASSED;
    }

    /**
     * Has this run out of time?
     *
     * Derived rather than scheduled. A queued job to fail the test after two minutes would be a
     * second source of truth about the same fact, and would leave tests hanging forever whenever
     * a worker was down — which, on a server where the queue is the thing being debugged, is
     * exactly when it would happen.
     */
    public function hasTimedOut(): bool
    {
        if (! $this->isRunning() || $this->sent_at === null) {
            return false;
        }

        return $this->sent_at->addSeconds($this->timeoutSeconds())->isPast();
    }

    public function timeoutSeconds(): int
    {
        return (int) config('help-center.inbound_test_timeout', 120);
    }

    /** Seconds left before this test gives up, for the countdown on the card. */
    public function secondsRemaining(): int
    {
        if (! $this->isRunning() || $this->sent_at === null) {
            return 0;
        }

        return max(0, (int) now()->diffInSeconds($this->sent_at->addSeconds($this->timeoutSeconds()), false));
    }

    /**
     * The token as it travels — in the subject line.
     *
     * The subject is the one part of a message that reliably survives a forwarding rule intact.
     * Custom headers are frequently stripped or rewritten by the forwarding provider, and the
     * body may be quoted, re-wrapped or converted between HTML and text.
     */
    public function subjectTag(): string
    {
        return '[PB-TEST-'.$this->test_token.']';
    }

    /** How the status reads, and which badge tone carries it. */
    /** @return array<string, string> */
    public function statusMeta(): array
    {
        return match ($this->status) {
            self::STATUS_PASSED => ['label' => 'Working', 'tone' => 'ok'],
            self::STATUS_PENDING, self::STATUS_SENT => ['label' => 'Testing', 'tone' => 'wait'],
            self::STATUS_TIMEOUT => ['label' => 'Failed', 'tone' => 'error'],
            self::STATUS_SEND_FAILED => ['label' => 'Failed', 'tone' => 'error'],
            self::STATUS_PARSE_FAILED => ['label' => 'Failed', 'tone' => 'error'],
            default => ['label' => 'Not tested', 'tone' => 'off'],
        };
    }

    /**
     * Which leg broke, in words the person reading the card can act on.
     *
     * The requirement is explicit that these must be distinguishable, because the remedy differs
     * completely: a send failure is ours, a timeout is their forwarding rule, a parse failure is
     * ours again.
     */
    public function explanation(): ?string
    {
        return match ($this->status) {
            self::STATUS_SEND_FAILED => 'The test email could not be sent. Postmark refused the outbound message, so it never reached your inbox.',
            self::STATUS_TIMEOUT => 'The test email was sent, but ProjectBlock did not receive the forwarded message.',
            self::STATUS_PARSE_FAILED => 'The forwarded email arrived, but ProjectBlock could not process it.',
            default => null,
        };
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        $meta = $this->statusMeta();

        return [
            'id' => $this->id,
            'status' => $this->status,
            'status_label' => $meta['label'],
            'status_tone' => $meta['tone'],
            'running' => $this->isRunning() && ! $this->hasTimedOut(),
            'passed' => $this->hasPassed(),
            'explanation' => $this->explanation(),
            'failure_reason' => $this->failure_reason,
            'seconds_remaining' => $this->secondsRemaining(),
            'test_email_address' => $this->test_email_address,
            'inbound_email_address' => $this->inbound_email_address,
            'sent_at' => $this->sent_at?->format('M j, Y \a\t g:i A'),
            'received_at' => $this->received_at?->format('M j, Y \a\t g:i A'),
            'received' => (array) $this->received_meta,
        ];
    }
}
