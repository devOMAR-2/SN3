<?php

use App\Providers\AppServiceProvider;
use App\Providers\IdentityServiceProvider;
use App\Providers\OperationsServiceProvider;
use App\Providers\TenancyServiceProvider;

return [
    AppServiceProvider::class,
    IdentityServiceProvider::class,
    OperationsServiceProvider::class,
    TenancyServiceProvider::class,
];
