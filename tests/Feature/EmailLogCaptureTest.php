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

    public function test_delete_all_empties_the_log(): void
    {
        config(['mail.capture_to_db' => true, 'mail.default' => 'array']);

        Mail::to('one@example.com')->send(new LoginCodeMail('111111', 10));
        Mail::to('two@example.com')->send(new LoginCodeMail('222222', 10));

        $this->delete(route('dev.emaillog.destroy'))
            ->assertRedirect(route('dev.emaillog'))
            ->assertSessionHas('status', '2 messages deleted.');

        $this->assertSame(0, EmailLog::query()->count());
    }

    public function test_delete_all_is_unavailable_when_the_viewer_is_off(): void
    {
        // The viewer is local-only, and so is emptying it — the button must not become a way
        // to wipe a captured log anywhere the page itself would 404.
        // The test env is already non-local, so turning capture off is enough to close the
        // viewer. (Overriding app.env to 'production' would also re-arm CSRF and mask this
        // as a 419.)
        config(['mail.capture_to_db' => false]);

        $this->delete(route('dev.emaillog.destroy'))->assertNotFound();
        $this->get(route('dev.emaillog'))->assertNotFound();
    }
}
