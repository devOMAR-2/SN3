<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration group 03, fourth part (DATABASE.md §5): `task_checklist_items` (CORE-005, ADR-023 §7).
 *
 * - `task_id → tasks(id)` cascades, so the purge removes items with their task (ADR-022 §5).
 * - `content varchar(300)` (API_SPEC §6.2: checklist text at most 300 characters), `is_done`,
 *   `completed_by` / `completed_at` (who ticked it, and when; cleared when unticked).
 * - `position` is the stored rank, kept dense (`0..n-1`) like status positions (ADR-021 §3);
 *   `UNIQUE(task_id, position)` is DEFERRABLE INITIALLY IMMEDIATE so a move rewrites every rank in
 *   one statement. It replaces the plain `(task_id, position)` index of DATABASE.md §5.
 * - `completed_by → users` sets NULL if the account is ever deleted; the tick itself stays.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_checklist_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('task_id');
            $table->string('content', 300);
            $table->boolean('is_done')->default(false);
            $table->decimal('position', 20, 10);
            $table->uuid('completed_by')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->foreign('task_id')->references('id')->on('tasks')->cascadeOnDelete();
            $table->foreign('completed_by')->references('id')->on('users')->nullOnDelete();
        });

        DB::statement("ALTER TABLE task_checklist_items ADD CONSTRAINT task_checklist_items_content_not_blank_check CHECK (btrim(content) <> '')");
        DB::statement('ALTER TABLE task_checklist_items ADD CONSTRAINT task_checklist_items_position_check CHECK (position >= 0)');
        DB::statement('ALTER TABLE task_checklist_items ADD CONSTRAINT task_checklist_items_completion_check CHECK ((is_done AND completed_at IS NOT NULL) OR (NOT is_done AND completed_at IS NULL AND completed_by IS NULL))');
        DB::statement('ALTER TABLE task_checklist_items ADD CONSTRAINT task_checklist_items_task_id_position_unique UNIQUE (task_id, position) DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(): void
    {
        Schema::dropIfExists('task_checklist_items');
    }
};
