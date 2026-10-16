<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration group 01 (DATABASE.md §3, §14, §20): `teams` and `team_memberships`.
 *
 * Ownership invariant (§3, §11): a team has exactly one membership with role `owner`, and it
 * belongs to `teams.owner_user_id`.
 * - The partial unique index makes a second owner membership impossible at any moment.
 * - Deferred constraint triggers re-check the team at COMMIT, so an ownership transfer may update
 *   `teams.owner_user_id` and both memberships in any order inside one transaction, but can never
 *   commit a team without its matching owner membership.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 160);
            $table->string('slug', 100)->unique();
            $table->foreignUuid('owner_user_id')->index()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampTz('deleted_at')->nullable();
        });

        // Slug format from API_SPEC.md §3; the column width follows DATABASE.md §3.
        DB::statement("ALTER TABLE teams ADD CONSTRAINT teams_slug_format_check CHECK (slug ~ '^[a-z0-9-]{3,40}$')");
        DB::statement("ALTER TABLE teams ADD CONSTRAINT teams_name_not_blank_check CHECK (btrim(name) <> '')");

        Schema::create('team_memberships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Memberships go with their team when the 30-day trash is purged (DECIDED-011).
            $table->foreignUuid('team_id')->constrained('teams')->cascadeOnDelete();
            // Removing a user must handle their memberships explicitly (ownership, audit).
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->string('role', 16);
            $table->timestampTz('joined_at');
            $table->timestampsTz();

            $table->unique(['team_id', 'user_id']);
            $table->index(['user_id', 'team_id']);
        });

        DB::statement("ALTER TABLE team_memberships ADD CONSTRAINT team_memberships_role_check CHECK (role IN ('owner', 'admin', 'member', 'viewer'))");
        DB::statement("CREATE UNIQUE INDEX team_memberships_one_owner_unique ON team_memberships (team_id) WHERE role = 'owner'");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION sn3_assert_team_owner() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                team_ids uuid[];
                checked uuid;
                expected_owner uuid;
                owner_member uuid;
            BEGIN
                IF TG_TABLE_NAME = 'teams' THEN
                    team_ids := ARRAY[NEW.id];
                ELSIF TG_OP = 'INSERT' THEN
                    team_ids := ARRAY[NEW.team_id];
                ELSIF TG_OP = 'DELETE' THEN
                    team_ids := ARRAY[OLD.team_id];
                ELSE
                    team_ids := ARRAY[OLD.team_id, NEW.team_id];
                END IF;

                FOREACH checked IN ARRAY team_ids LOOP
                    SELECT owner_user_id INTO expected_owner FROM teams WHERE id = checked;

                    -- The team itself was removed in this transaction; nothing left to check.
                    CONTINUE WHEN NOT FOUND;

                    SELECT user_id INTO owner_member
                    FROM team_memberships
                    WHERE team_id = checked AND role = 'owner';

                    IF owner_member IS DISTINCT FROM expected_owner THEN
                        RAISE EXCEPTION 'team % must have exactly one owner membership for teams.owner_user_id', checked
                            USING ERRCODE = 'check_violation', CONSTRAINT = 'teams_owner_membership';
                    END IF;
                END LOOP;

                RETURN NULL;
            END;
            $$;

            CREATE CONSTRAINT TRIGGER teams_owner_membership
                AFTER INSERT OR UPDATE OF owner_user_id ON teams
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION sn3_assert_team_owner();

            CREATE CONSTRAINT TRIGGER team_memberships_owner_membership
                AFTER INSERT OR UPDATE OR DELETE ON team_memberships
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION sn3_assert_team_owner();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('team_memberships');
        Schema::dropIfExists('teams');
        DB::statement('DROP FUNCTION IF EXISTS sn3_assert_team_owner()');
    }
};
