<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Captured outbound email (local/non-prod) for the /emaillog viewer.
 */
class EmailLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['to', 'from', 'subject', 'html_body', 'text_body', 'mailer', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
