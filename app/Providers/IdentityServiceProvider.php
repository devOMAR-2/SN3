<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Identity concerns (FND-004): request and auth rate limiters. Password policy: App\Support\PasswordRules.
 */
class IdentityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    private function configureRateLimiting(): void
    {
        // Every web and /api/v1 request: 120/min per user (IP for guests) plus a per-second burst cap
        // (DECIDED-004, LIM-03). Exceeding either returns 429 with Retry-After.
        RateLimiter::for('requests', function (Request $request): array {
            $key = self::actorKey($request);

            return array_values(array_filter([
                self::perMinute('sn3.rate_limits.requests_per_minute', 'minute:'.$key),
                self::perSecond('sn3.rate_limits.burst_per_second', 'second:'.$key),
            ]));
        });

        // Credential stuffing across many accounts from one address. Per-account+IP lockout lives in LoginRequest.
        RateLimiter::for('auth-login', fn (Request $request): Limit => self::perMinute(
            'sn3.rate_limits.login_per_minute',
            'login-ip:'.$request->ip(),
            multiplier: 4,
        ) ?? Limit::none());

        RateLimiter::for('auth-register', fn (Request $request): Limit => self::perMinute(
            'sn3.rate_limits.register_per_minute',
            'register:'.$request->ip(),
        ) ?? Limit::none());

        RateLimiter::for('auth-password', fn (Request $request): Limit => self::perMinute(
            'sn3.rate_limits.password_reset_per_minute',
            'password:'.$request->ip(),
        ) ?? Limit::none());

        // Project create and PATCH: a taken key answers 409 even when the project holding it is
        // private, so key probing is slowed per user (ADR-020 §10).
        RateLimiter::for('project-writes', fn (Request $request): Limit => self::perMinute(
            'sn3.rate_limits.project_writes_per_minute',
            'project-writes:'.self::actorKey($request),
        ) ?? Limit::none());

        RateLimiter::for('auth-verification', fn (Request $request): Limit => self::perMinute(
            'sn3.rate_limits.verification_per_minute',
            'verification:'.self::actorKey($request),
        ) ?? Limit::none());

        // Team export requests (CORE-012, ADR-024 §5): each one reads the whole team, so they are
        // limited per user per hour, on top of one export in flight per team.
        RateLimiter::for('team-exports', function (Request $request): Limit {
            $max = config()->integer('sn3.rate_limits.team_exports_per_hour');

            return $max > 0 ? Limit::perHour($max)->by('team-exports:'.self::actorKey($request)) : Limit::none();
        });

        // Invitations (CORE-002, ADR-025 §7; API_SPEC.md §1.4 "stricter limits for invitations").
        RateLimiter::for('invitations', function (Request $request): Limit {
            $max = config()->integer('sn3.rate_limits.invitations_per_hour');

            return $max > 0 ? Limit::perHour($max)->by('invitations:'.self::actorKey($request)) : Limit::none();
        });

        // Token preview and accept: per user once signed in, and four times that per IP (several
        // people in one office may redeem the same share link).
        RateLimiter::for('invitation-tokens', fn (Request $request): array => array_values(array_filter([
            self::perMinute('sn3.rate_limits.invitation_tokens_per_minute', 'invitation-tokens-ip:'.$request->ip(), multiplier: 4),
            $request->user() === null ? null : self::perMinute('sn3.rate_limits.invitation_tokens_per_minute', 'invitation-tokens:'.self::actorKey($request)),
        ])));
    }

    private static function actorKey(Request $request): string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_string($id) ? 'user:'.$id : 'ip:'.$request->ip();
    }

    /**
     * Returns null when the configured value is 0 (limit disabled, self-hosted only).
     */
    private static function perMinute(string $configKey, string $by, int $multiplier = 1): ?Limit
    {
        $max = config()->integer($configKey) * $multiplier;

        return $max > 0 ? Limit::perMinute($max)->by($by) : null;
    }

    private static function perSecond(string $configKey, string $by): ?Limit
    {
        $max = config()->integer($configKey);

        return $max > 0 ? Limit::perSecond($max)->by($by) : null;
    }
}
