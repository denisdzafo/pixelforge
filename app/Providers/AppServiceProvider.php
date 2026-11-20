<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Only these proxies may set X-Forwarded-* (client IP, https); empty = none (config/app.php trusted_proxies)
        TrustProxies::at(config('app.trusted_proxies') ?: []);

        $this->configureRateLimiting();
    }

    /**
     * Limiters used by the routes (`throttle:api`, `throttle:auth`), see config/rate_limits.php.
     * The api limit is per user: it runs after jwtAuth, which verified the access token.
     */
    private function configureRateLimiting()
    {
        $tooMany = fn () => response()->json(['error' => 'Too many requests. Please wait a minute and try again.'], 429);

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(config('rate_limits.api_per_minute'))
            ->by('api|' . ($request->attributes->get('userModel')->id ?? $request->ip()))
            ->response($tooMany));

        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(config('rate_limits.auth_per_minute'))
            ->by('auth|' . $request->ip())
            ->response($tooMany));
    }
}
