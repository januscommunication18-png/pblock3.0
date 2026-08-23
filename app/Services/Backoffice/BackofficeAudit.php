<?php

namespace App\Services\Backoffice;

use App\Models\BackofficeAuditLog;
use App\Models\BackofficeUser;
use Illuminate\Support\Facades\Request;
use Throwable;

/**
 * Writing the Back Office audit trail (docs/features/backoffice-auth.md, §11).
 *
 * ONE place that writes these, for the reason `RequestActivity` is one place on the Help Center
 * side: every row needs the IP and the user agent, and a trail that captures them at each call
 * site is a trail that has them for the calls somebody remembered.
 *
 * Nothing here throws. An audit write failing must not take down the sign-in it was recording —
 * but it must not pass silently either, so the failure goes to the application log, which is the
 * one place left to say so.
 */
class BackofficeAudit
{
    /** @param  array<string, mixed>  $meta */
    public function record(
        string $action,
        ?string $email = null,
        ?BackofficeUser $user = null,
        bool $succeeded = true,
        array $meta = [],
    ): void {
        try {
            BackofficeAuditLog::create([
                'backoffice_user_id' => $user?->id,
                // As TYPED where there is no user — on a failure the address somebody tried is
                // the whole point of the row.
                'email' => $email !== null ? mb_strtolower(trim($email)) : $user?->email,
                'action' => $action,
                'ip' => Request::ip(),
                // Truncated: a user agent is attacker-controlled free text on an unauthenticated
                // endpoint, and the column should not be a place to store a megabyte.
                'user_agent' => mb_substr((string) Request::userAgent(), 0, 500) ?: null,
                'succeeded' => $succeeded,
                'meta' => $meta === [] ? null : $meta,
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** @param  array<string, mixed>  $meta */
    public function failure(string $action, ?string $email = null, array $meta = []): void
    {
        $this->record($action, $email, null, false, $meta);
    }
}
