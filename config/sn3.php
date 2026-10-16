<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Registration (FR-AUTH-001)
    |--------------------------------------------------------------------------
    |
    | Public sign-up is enabled on Cloud. Self-hosted operators may close it;
    | existing users can still sign in and reset passwords.
    |
    */

    'registration' => [
        'enabled' => (bool) env('SN3_REGISTRATION_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Passwords (API_SPEC.md §2)
    |--------------------------------------------------------------------------
    |
    | 12–128 characters. The breached-password check calls the Have I Been
    | Pwned range API (k-anonymity: only a 5-character hash prefix leaves the
    | server). It is off by default so no third-party service is mandatory.
    |
    */

    'passwords' => [
        'min' => 12,
        'max' => 128,
        'check_breached' => (bool) env('SN3_PASSWORD_BREACH_CHECK', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limits (DECIDED-004, API_SPEC.md §1.4)
    |--------------------------------------------------------------------------
    |
    | `requests_per_minute` applies to every web and /api/v1 request, keyed by
    | user (or IP for guests); `burst_per_second` stops short spikes. Auth
    | endpoints have their own stricter limits. Set a value to 0 to disable that
    | limit (self-hosted only; Cloud keeps the defaults).
    |
    */

    'rate_limits' => [
        'requests_per_minute' => (int) env('SN3_RATE_LIMIT_PER_MINUTE', 120),
        'burst_per_second' => (int) env('SN3_RATE_LIMIT_BURST_PER_SECOND', 20),
        'login_per_minute' => (int) env('SN3_RATE_LIMIT_LOGIN_PER_MINUTE', 5),
        'register_per_minute' => (int) env('SN3_RATE_LIMIT_REGISTER_PER_MINUTE', 5),
        'password_reset_per_minute' => (int) env('SN3_RATE_LIMIT_PASSWORD_RESET_PER_MINUTE', 5),
        'verification_per_minute' => (int) env('SN3_RATE_LIMIT_VERIFICATION_PER_MINUTE', 6),
        // Project create and PATCH, per user: slows probing of project keys (ADR-020 §10).
        'project_writes_per_minute' => (int) env('SN3_RATE_LIMIT_PROJECT_WRITES_PER_MINUTE', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | Email verification
    |--------------------------------------------------------------------------
    */

    'verification' => [
        'expire_minutes' => (int) env('SN3_VERIFICATION_EXPIRE_MINUTES', 60),
    ],

];
