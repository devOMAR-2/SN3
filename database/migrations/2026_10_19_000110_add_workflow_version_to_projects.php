<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `projects.workflow_version` (CORE-004, ADR-021 §3): the version of a project's workflow
 * configuration as a whole (API_SPEC.md §1.4 "workflow configuration"). Every status create,
 * update, delete and reorder increments it under the project lock; `PUT /statuses/order` requires
 * it in `If-Match`, so a reorder made from a stale list is a 412 instead of a lost update.
 *
 * It is separate from `projects.version` (metadata and settings), so workflow edits never make a
 * project PATCH fail with 412, and the reverse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->bigInteger('workflow_version')->default(1);
        });

        DB::statement('ALTER TABLE projects ADD CONSTRAINT projects_workflow_version_check CHECK (workflow_version > 0)');
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('workflow_version');
        });
    }
};
