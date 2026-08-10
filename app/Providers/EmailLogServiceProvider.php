<?php

namespace App\Providers;

use App\Listeners\CaptureOutgoingEmail;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the outbound-email capture used by the /emaillog viewer.
 * Add this class to bootstrap/providers.php.
 */
class EmailLogServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(MessageSending::class, CaptureOutgoingEmail::class);
    }
}
