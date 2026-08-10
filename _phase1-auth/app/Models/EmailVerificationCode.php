<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailVerificationCode extends Model
{
    public const PURPOSE_SIGNUP = 'signup';
    public const PURPOSE_LOGIN  = 'login';

    protected $fillable = ['email', 'code_hash', 'purpose', 'expires_at', 'consumed_at', 'attempts'];

    protected function casts(): array
    {
        return [
            'expires_at'  => 'datetime',
            'consumed_at' => 'datetime',
            'attempts'    => 'integer',
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
