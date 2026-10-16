<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CORE-001: optional team description (API_SPEC.md §3 create/update body, max 1000 characters;
 * BRD FR-TEAM-001). Expand-only: nullable, no backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->string('description', 1000)->nullable()->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
