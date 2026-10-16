<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Task-key integrity in PostgreSQL itself (DATABASE.md §0.5, §5, §19; CORE-004, ADR-021).
 *
 * A task key is `projects.project_key || '-' || tasks.task_number`, and task numbers come from
 * `projects.next_task_number` (App\Domain\Projects\TaskNumbers). Two rules keep issued keys valid
 * for ever, whatever writes the row:
 *
 * - `next_task_number` never decreases, so a task number is never issued twice.
 * - Once a number has been issued (`next_task_number > 1`), `project_key` is frozen (DECIDED,
 *   DATABASE.md §5 "freeze project_key after the first task is created"; PROJ-01). Deleting tasks
 *   does not unfreeze it: links to their keys may still exist.
 *
 * UpdateProject refuses a frozen key change with 409 before it reaches the database; this trigger
 * is the backstop for every other path.
 *
 * `CREATE OR REPLACE`: `migrate:fresh` drops tables (and so the trigger) but not functions.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION projects_guard_task_numbering() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.next_task_number < OLD.next_task_number THEN
                    RAISE EXCEPTION 'projects_next_task_number_monotonic: next_task_number cannot decrease'
                        USING ERRCODE = 'check_violation', CONSTRAINT = 'projects_next_task_number_monotonic';
                END IF;

                IF NEW.project_key IS DISTINCT FROM OLD.project_key AND OLD.next_task_number > 1 THEN
                    RAISE EXCEPTION 'projects_project_key_frozen: project_key is frozen after the first task'
                        USING ERRCODE = 'check_violation', CONSTRAINT = 'projects_project_key_frozen';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER projects_guard_task_numbering
                BEFORE UPDATE OF project_key, next_task_number ON projects
                FOR EACH ROW EXECUTE FUNCTION projects_guard_task_numbering();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS projects_guard_task_numbering ON projects;
            DROP FUNCTION IF EXISTS projects_guard_task_numbering();
            SQL);
    }
};
