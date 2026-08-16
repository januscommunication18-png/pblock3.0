<?php

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use App\Models\EmailLog;
use Illuminate\Http\RedirectResponse;
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

    /**
     * DELETE /emaillog — empty the log.
     *
     * Truncate rather than delete row-by-row: this is a local scratch log, so there is nothing
     * to preserve and no ids worth keeping stable. Guarded like every other action here.
     */
    public function destroyAll(): RedirectResponse
    {
        $this->guard();

        $deleted = EmailLog::query()->delete();

        return redirect()
            ->route('dev.emaillog')
            ->with('status', $deleted === 1 ? '1 message deleted.' : "{$deleted} messages deleted.");
    }

    /** GET /emaillog/{email} */
    public function show(EmailLog $email): View
    {
        $this->guard();

        return view('dev.emaillog.show', ['email' => $email]);
    }
}
