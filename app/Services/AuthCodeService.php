<?php

namespace App\Services;

use App\Mail\BackofficeCodeMail;
use App\Mail\LoginCodeMail;
use App\Models\EmailVerificationCode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Issues and verifies short-lived, single-use 6-digit email codes (spec D-A3).
 * Codes are hashed at rest and never logged in plaintext.
 */
class AuthCodeService
{
    private const TTL_MINUTES = 10;

    private const MAX_ATTEMPTS = 5;

    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    /**
     * Create a fresh code for the email, invalidating any prior unconsumed codes,
     * and email it. Returns nothing sensitive to the caller.
     */
    public function issue(string $email, string $purpose = EmailVerificationCode::PURPOSE_SIGNUP): void
    {
        $email = self::normalizeEmail($email);

        // Invalidate outstanding codes for this email+purpose.
        EmailVerificationCode::query()
            ->where('email', $email)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        EmailVerificationCode::create([
            'email' => $email,
            'code_hash' => Hash::make($code),
            'purpose' => $purpose,
            'expires_at' => Carbon::now()->addMinutes(self::TTL_MINUTES),
            'attempts' => 0,
        ]);

        /*
         * Sent synchronously and NOT swallowed.
         *
         * The code row is already written, so a silent failure here is the worst outcome
         * available: the user is told to check their email, no email exists, and nothing
         * anywhere records why. WorkspaceInviter deliberately reports-rather-than-throws
         * because an invitation is recoverable from the Members screen — a signup code is not
         * recoverable from anywhere, so this one fails loudly.
         *
         * The catch exists only to LOG the reason before rethrowing. Without it the sole log
         * line is `auth.code.issued`, which is written after the send and so is simply absent
         * on failure — leaving "the account exists but no email arrived" with no trail at all.
         */
        try {
            /*
             * The Back Office gets its own Mailable (docs/features/backoffice-auth.md, §3).
             *
             * Every RULE about the code is shared — that is the point of reusing this service
             * (BO-D2) — but the WORDING must not be. A platform-administration code arriving
             * under the customer application's subject line is the message somebody skims,
             * assumes belongs to the app they were already signing into, and types in wherever
             * it was asked for.
             */
            $mail = $purpose === EmailVerificationCode::PURPOSE_BACKOFFICE
                ? new BackofficeCodeMail($code, self::TTL_MINUTES)
                : new LoginCodeMail($code, self::TTL_MINUTES);

            Mail::to($email)->send($mail);
        } catch (Throwable $e) {
            Log::channel(config('logging.default'))->error('auth.code.send_failed', [
                'email' => $email,
                'purpose' => $purpose,
                'mailer' => config('mail.default'),
                'from' => config('mail.from.address'),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        Log::channel(config('logging.default'))->info('auth.code.issued', [
            'email' => $email, 'purpose' => $purpose, 'mailer' => config('mail.default'),
        ]);
    }

    /**
     * Verify a submitted code. Returns true on success (and consumes the code).
     * Uniform failure for expired/invalid/too-many-attempts so account state is not leaked.
     */
    public function verify(string $email, string $code, string $purpose = EmailVerificationCode::PURPOSE_SIGNUP): bool
    {
        $email = self::normalizeEmail($email);

        $record = EmailVerificationCode::query()
            ->where('email', $email)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->latest('id')
            ->first();

        if (! $record || $record->isExpired() || $record->attempts >= self::MAX_ATTEMPTS) {
            return false;
        }

        $record->increment('attempts');

        if (! Hash::check($code, $record->code_hash)) {
            return false;
        }

        $record->forceFill(['consumed_at' => now()])->save();

        Log::channel(config('logging.default'))->info('auth.code.consumed', [
            'email' => $email, 'purpose' => $purpose,
        ]);

        return true;
    }
}
