<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserIdentity extends Model
{
    public const PROVIDER_EMAIL = 'email';

    public const PROVIDER_GOOGLE = 'google';

    public const PROVIDER_GITHUB = 'github';

    public const PROVIDER_SSO = 'sso';

    protected $fillable = ['user_id', 'provider', 'provider_subject'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
