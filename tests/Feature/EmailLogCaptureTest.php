<?php

namespace Tests\Feature;

use App\Mail\LoginCodeMail;
use App\Models\EmailLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The /emaillog capture (spec D-A5).
 *
 * Regression guard: App\Listeners\CaptureOutgoingEmail is auto-discovered, and was for a
 * while ALSO registered explicitly by a service provider. Subscribed twice, it wrote two
 * rows per message, so the dev viewer showed every email — invitations included — as a
 * duplicate pair and looked like the app was sending each one twice.
 */
class EmailLogCaptureTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_sent_email_is_captured_exactly_once(): void
    {
        config(['mail.capture_to_db' => true, 'mail.default' => 'array']);

        Mail::to('recipient@example.com')->send(new LoginCodeMail('123456', 10));

        $this->assertSame(1, EmailLog::query()->where('to', 'like', '%recipient@example.com%')->count());
    }
}
