<?php

use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    \App\Providers\EmailLogServiceProvider::class,
    \App\Providers\TenancyServiceProvider::class,
];
