<?php

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use App\Models\EmailLog;
use Illuminate\View\View;

/**
 * Local outbound-email viewer at /emaillog (spec D-A5). Local env only.
 * Reads rows captured by App\Listeners\CaptureOutgoingEmail.
 */
class EmailLogController extends Controller
{
    private function guard(): void
    {
        abort_unless(app()->environment('local') || config('mail.capture_to_db'), 404);
    }

    /** GET /emaillog */
    public function index(): View
    {
        $this->guard();

        $emails = EmailLog::orderByDesc('id')->limit(200)->get();

        return view('dev.emaillog.index', ['emails' => $emails]);
    }

    /** GET /emaillog/{email} */
    public function show(EmailLog $email): View
    {
        $this->guard();

        return view('dev.emaillog.show', ['email' => $email]);
    }
}
