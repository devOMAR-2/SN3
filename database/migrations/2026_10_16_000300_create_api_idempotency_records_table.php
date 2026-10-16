<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE.md §20 / API_SPEC.md §1.4: stored results for `Idempotency-Key` requests,
 * unique per actor, route and key, kept for 24 hours.
 *
 * `route_key` is the HTTP method plus the request path (e.g. `POST /api/v1/teams/{uuid}/invitations`),
 * so the same key used under two teams or two endpoints never collides. Only the response of a
 * successful (2xx) request is stored; a matching retry gets the same status and JSON body.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_idempotency_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('actor_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('team_id')->nullable()->index()->constrained('teams')->cascadeOnDelete();
            $table->string('route_key', 255);
            $table->uuid('idempotency_key');
            $table->char('request_digest', 64);
            $table->smallInteger('response_status');
            $table->jsonb('response_json');
            $table->timestampTz('expires_at')->index();
            $table->timestampsTz();

            $table->unique(['actor_user_id', 'route_key', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_idempotency_records');
    }
};
