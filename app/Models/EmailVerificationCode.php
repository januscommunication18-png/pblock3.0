<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailVerificationCode extends Model
{
    public const PURPOSE_SIGNUP = 'signup';

    public const PURPOSE_LOGIN = 'login';

    /**
     * The Back Office's security-verification code (docs/features/backoffice-auth.md, §3).
     *
     * A new PURPOSE rather than a parallel table and a parallel service (BO-D2): every rule the
     * Back Office asks of its code — 6 digits, hashed at rest, single-use, 10-minute expiry, 5
     * attempts, prior codes killed on reissue — is already implemented once in `AuthCodeService`,
     * and a second copy would be a second place for those rules to drift silently apart.
     */
    public const PURPOSE_BACKOFFICE = 'backoffice';

    protected $fillable = ['email', 'code_hash', 'purpose', 'expires_at', 'consumed_at', 'attempts'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }
}
