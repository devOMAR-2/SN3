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
        // Team export requests, per user per hour (CORE-012, ADR-024 §5).
        'team_exports_per_hour' => (int) env('SN3_RATE_LIMIT_TEAM_EXPORTS_PER_HOUR', 10),
        // Attachment uploads, per user per minute: a stricter limit for uploads (API_SPEC.md §1.4;
        // CORE-008, ADR-027 §6).
        'attachment_uploads_per_minute' => (int) env('SN3_RATE_LIMIT_ATTACHMENT_UPLOADS_PER_MINUTE', 20),

        // Invitations (CORE-002, ADR-025 §6): creating them, per user per hour (each email invitation
        // sends mail), and previewing or accepting tokens, per IP and per user per minute.
        'invitations_per_hour' => (int) env('SN3_RATE_LIMIT_INVITATIONS_PER_HOUR', 30),
        'invitation_tokens_per_minute' => (int) env('SN3_RATE_LIMIT_INVITATION_TOKENS_PER_MINUTE', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Email verification
    |--------------------------------------------------------------------------
    */

    'verification' => [
        'expire_minutes' => (int) env('SN3_VERIFICATION_EXPIRE_MINUTES', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Team exports (CORE-012, ADR-024)
    |--------------------------------------------------------------------------
    |
    | Archives are written to this dedicated private disk (under `archives/`)
    | and deleted 24 hours after they complete (API_SPEC.md §13). The default
    | `exports` disk is local (storage/app/exports) and never served directly;
    | Cloud may point this at a private S3 disk.
    |
    */

    'exports' => [
        // A dedicated private disk; SN3 only uses keys under `archives/` on it.
        'disk' => (string) env('SN3_EXPORTS_DISK', 'exports'),
        // Local working directory for archives being built (one subdirectory per export).
        'work_dir' => (string) env('SN3_EXPORTS_WORK_DIR', storage_path('app/exports/work')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Task attachments (CORE-008, ADR-027; DECIDED-004, API_SPEC.md §6.3)
    |--------------------------------------------------------------------------
    |
    | Files go to this dedicated private disk (under `objects/`), never to a
    | public one, and leave it only through the authorized download route.
    | Cloud keeps 25 MiB per file and 2 GiB per team; self-hosted operators may
    | change both (0 means no limit). The PHP upload limits (upload_max_filesize,
    | post_max_size in deploy/docker/php/sn3.ini) must be at least as large.
    |
    */

    'attachments' => [
        'disk' => (string) env('SN3_ATTACHMENTS_DISK', 'attachments'),
        'max_bytes' => (int) env('SN3_ATTACHMENTS_MAX_BYTES', 25 * 1024 * 1024),
        'team_quota_bytes' => (int) env('SN3_ATTACHMENTS_TEAM_QUOTA_BYTES', 2 * 1024 * 1024 * 1024),
        // Disks attachments were stored on before SN3_ATTACHMENTS_DISK changed (comma-separated). The
        // purge and the sweep clean them too, besides every disk an attachment row still names.
        'previous_disks' => array_values(array_filter(array_map('trim', explode(',', (string) env('SN3_ATTACHMENTS_PREVIOUS_DISKS', ''))))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Notification emails (CORE-009, ADR-029 §5; API_SPEC.md §9)
    |--------------------------------------------------------------------------
    |
    | A notification's email is held for this many seconds: events collapsed
    | into the notification meanwhile go out in one email, and nothing is sent
    | for a notification the recipient already read in the inbox. 0 sends as
    | soon as a queue worker picks the job up.
    |
    */

    'notifications' => [
        'email_delay_seconds' => (int) env('SN3_NOTIFICATION_EMAIL_DELAY_SECONDS', 120),
    ],

];
