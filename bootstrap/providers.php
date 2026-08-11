<?php

use App\Providers\AppServiceProvider;
use App\Providers\TenancyServiceProvider;

return [
    AppServiceProvider::class,
    // No EmailLogServiceProvider: App\Listeners\CaptureOutgoingEmail is picked up by
    // Laravel's listener auto-discovery. Registering it here as well subscribed it twice, so
    // every outgoing message was written to email_logs (and shown in /emaillog) twice.
    TenancyServiceProvider::class,
];
