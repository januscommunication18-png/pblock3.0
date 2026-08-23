<?php

namespace App\Http\Controllers\Backoffice;

use App\Http\Controllers\Controller;
use App\Models\BackofficeAuditLog;
use App\Models\BackofficeUser;
use App\Models\EmailVerificationCode;
use App\Services\AuthCodeService;
use App\Services\Backoffice\BackofficeAudit;
use App\Services\Backoffice\BackofficeVerification;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Throwable;

/**
 * Step 1 and step 2 of the Back Office gate (docs/features/backoffice-auth.md, §2–§4):
 * the authorized-email screen, the code screen, and the resend.
 *
 * The rule that shapes every method here is §2's last line: **do not reveal whether the account
 * exists**. An unknown address, a disabled account and an authorized one all produce the same
 * message, the same redirect and the same amount of work (BO-D7) — a fast path for unknown
 * addresses is an account-enumeration oracle even when the wording is identical.
 */
class SecurityVerificationController extends Controller
{
    /** What every failed lookup says, whatever actually went wrong. */
    private const UNIFORM_ERROR = "We couldn't verify your access. Please check your information and try again.";

    public function __construct(
        private readonly AuthCodeService $codes,
        private readonly BackofficeVerification $verification,
        private readonly BackofficeAudit $audit,
    ) {}

    /** GET /backoffice — the Security Verification screen. */
    public function show(): View|RedirectResponse
    {
        // Already through the gate? Do not make them do it twice.
        if ($this->verification->isVerified()) {
            return redirect()->route('backoffice.login.show');
        }

        return view('backoffice.verify-email');
    }

    /**
     * POST /backoffice — check the email and issue a code.
     *
     * Always redirects to the code screen, whether or not the address was authorized. The screen
     * it lands on says a code was sent "if the address is authorized", so the page cannot be
     * read as confirmation either.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        $email = mb_strtolower(trim($data['email']));

        $this->audit->record(BackofficeAuditLog::CODE_REQUESTED, $email);

        $user = BackofficeUser::query()->where('email', $email)->first();

        if ($user === null || ! $user->mayVerify()) {
            /*
             * The work an authorized address would have caused, done anyway (BO-D7).
             *
             * Issuing a code hashes it and writes a row; skipping that for unknown addresses
             * makes the unauthorized path measurably faster, and a stopwatch then answers the
             * question the wording refuses to. One bcrypt is the cheapest honest equivalent.
             */
            Hash::make('backoffice-timing-equaliser');

            $this->audit->failure(BackofficeAuditLog::CODE_FAILED, $email, [
                'reason' => $user === null ? 'unknown_email' : 'inactive',
            ]);

            // The pending address is still remembered, so the code screen can render its masked
            // address and behave identically to the authorized case.
            $this->verification->startPending($email);

            return redirect()->route('backoffice.verify.code');
        }

        $this->verification->startPending($email);

        try {
            $this->codes->issue($email, EmailVerificationCode::PURPOSE_BACKOFFICE);
        } catch (Throwable $e) {
            /*
             * The send failed. Reported, but NOT distinguished on screen.
             *
             * Telling this visitor "we could not send your email" would confirm the address is
             * authorized, which is the one thing this endpoint must never do. The operator finds
             * out from the audit row and the application log; the visitor sees what everybody
             * sees and can press Resend.
             */
            report($e);

            $this->audit->failure(BackofficeAuditLog::CODE_SENT, $email, ['error' => $e->getMessage()]);

            return redirect()->route('backoffice.verify.code');
        }

        $this->audit->record(BackofficeAuditLog::CODE_SENT, $email, $user);

        return redirect()->route('backoffice.verify.code');
    }

    /** GET /backoffice/verify-code — the six-box code screen. */
    public function code(): View|RedirectResponse
    {
        $email = $this->verification->pendingEmail();

        if ($email === null) {
            return redirect()->route('backoffice.verify.show');
        }

        if ($this->verification->isVerified()) {
            return redirect()->route('backoffice.login.show');
        }

        return view('backoffice.verify-code', ['masked' => self::mask($email)]);
    }

    /**
     * POST /backoffice/verify-code — check the code.
     *
     * The email comes from the SESSION, never from the request: the code was issued to one
     * address, and letting the form name a different one would make step 1 decorative (BO-D4).
     */
    public function verify(Request $request): RedirectResponse
    {
        $email = $this->verification->pendingEmail();

        if ($email === null) {
            return redirect()->route('backoffice.verify.show');
        }

        $data = $request->validate([
            'code' => ['required', 'string'],
        ]);

        // The six boxes arrive as one string; strip anything that is not a digit so a paste of
        // "482 913" behaves like a person typing it.
        $code = preg_replace('/\D/', '', $data['code']) ?? '';

        if (! $this->codes->verify($email, $code, EmailVerificationCode::PURPOSE_BACKOFFICE)) {
            $this->audit->failure(BackofficeAuditLog::CODE_FAILED, $email, ['reason' => 'bad_code']);

            return back()->withErrors(['code' => 'That code is not valid. Check it and try again.']);
        }

        /*
         * Re-checked AFTER the code, not only before it.
         *
         * An account can be disabled in the ten minutes a code is live, and the check at step 1
         * is by then a statement about the past. Consuming the code first is deliberate: it is
         * spent either way, so a disabled account cannot re-use it.
         */
        $user = BackofficeUser::query()->where('email', $email)->first();

        if ($user === null || ! $user->mayVerify()) {
            $this->verification->forget();
            $this->audit->failure(BackofficeAuditLog::CODE_FAILED, $email, ['reason' => 'inactive_after_code']);

            return redirect()->route('backoffice.verify.show')
                ->withErrors(['email' => self::UNIFORM_ERROR]);
        }

        $this->verification->confirm($email);
        $this->audit->record(BackofficeAuditLog::CODE_VERIFIED, $email, $user);

        return redirect()->route('backoffice.login.show');
    }

    /**
     * POST /backoffice/resend-code.
     *
     * Issuing a new code invalidates the previous one — `AuthCodeService::issue()` consumes any
     * outstanding code for the address and purpose, which is §3's "invalidated when a new code
     * is requested" already implemented (BO-D2). The cooldown and the hourly cap are route
     * throttles; see routes/backoffice.php.
     */
    public function resend(): RedirectResponse
    {
        $email = $this->verification->pendingEmail();

        if ($email === null) {
            return redirect()->route('backoffice.verify.show');
        }

        $this->audit->record(BackofficeAuditLog::CODE_REQUESTED, $email, meta: ['resend' => true]);

        $user = BackofficeUser::query()->where('email', $email)->first();

        if ($user === null || ! $user->mayVerify()) {
            Hash::make('backoffice-timing-equaliser');

            return back()->with('status', 'If that address is authorized, a new code is on its way.');
        }

        try {
            $this->codes->issue($email, EmailVerificationCode::PURPOSE_BACKOFFICE);
            $this->audit->record(BackofficeAuditLog::CODE_SENT, $email, $user, meta: ['resend' => true]);
        } catch (Throwable $e) {
            report($e);
            $this->audit->failure(BackofficeAuditLog::CODE_SENT, $email, ['error' => $e->getMessage()]);
        }

        return back()->with('status', 'If that address is authorized, a new code is on its way.');
    }

    /**
     * `r*****@gmail.com` — the requirement's own masking (§3).
     *
     * Shown only to somebody who already typed the address, so it reveals nothing they did not
     * supply. Its job is to catch a typo before they go looking in the wrong inbox.
     */
    public static function mask(string $email): string
    {
        $at = mb_strpos($email, '@');

        if ($at === false || $at < 1) {
            return $email;
        }

        $local = mb_substr($email, 0, $at);
        $domain = mb_substr($email, $at);

        return mb_substr($local, 0, 1).str_repeat('*', max(1, mb_strlen($local) - 1)).$domain;
    }
}
