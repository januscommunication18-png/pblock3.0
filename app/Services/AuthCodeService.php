<?php

namespace App\Services;

use App\Mail\LoginCodeMail;
use App\Models\EmailVerificationCode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Issues and verifies short-lived, single-use 6-digit email codes (spec D-A3).
 * Codes are hashed at rest and never logged in plaintext.
 */
class AuthCodeService
{
    private const TTL_MINUTES  = 10;
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
            'email'      => $email,
            'code_hash'  => Hash::make($code),
            'purpose'    => $purpose,
            'expires_at' => Carbon::now()->addMinutes(self::TTL_MINUTES),
            'attempts'   => 0,
        ]);

        Mail::to($email)->send(new LoginCodeMail($code, self::TTL_MINUTES));

        Log::channel(config('logging.default'))->info('auth.code.issued', [
            'email' => $email, 'purpose' => $purpose,
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
