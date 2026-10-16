<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expand-only (CORE-005, ADR-023 §9): the allowlisted response headers of a stored idempotent
 * result, so a replay answers exactly like the original, e.g. a created task's `ETag` (its version,
 * the value its next `If-Match` needs). NULL on records written before this migration: they replay
 * without those headers, as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_idempotency_records', function (Blueprint $table) {
            $table->jsonb('response_headers')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('api_idempotency_records', function (Blueprint $table) {
            $table->dropColumn('response_headers');
        });
    }
};
