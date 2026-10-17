<?php

namespace App\Providers;

use App\Http\Middleware\StartSession;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Session\Middleware\StartSession as BaseStartSession;
use Illuminate\Session\SessionManager;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // API GETs are never stored as the session's previous URL (tokens in paths, ADR-025 §2).
        $this->app->singleton(BaseStartSession::class, fn ($app): StartSession => new StartSession(
            $app->make(SessionManager::class),
            fn (): CacheFactory => $app->make(CacheFactory::class),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
