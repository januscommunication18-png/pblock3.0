<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A row exists only when the user has set a password (spec D-A2).
 */
class UserPasswordCredential extends Model
{
    protected $fillable = ['user_id', 'password_hash', 'password_set_at'];

    protected $hidden = ['password_hash'];

    protected function casts(): array
    {
        return ['password_set_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
