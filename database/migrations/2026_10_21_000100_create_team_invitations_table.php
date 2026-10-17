<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DATABASE.md §3 `team_invitations` (CORE-002, ADR-025; DECIDED-007).
 *
 * - Only the SHA-256 of the token is stored (`token_hash`, unique). The raw token is shown once, on
 *   creation, and never written to any table or log.
 * - Email-bound invitations (`email` set) have one use; share links (`email` NULL) have 20. Both
 *   expire 7 days after creation. Only an email-bound invitation can carry the Admin role.
 * - `use_count` never exceeds `max_uses`; `accepted_at` is set exactly when the last use is taken,
 *   so for an email invitation it is the redemption time.
 * - `team_id` cascades, as every key to `teams` must (ADR-022 §5, TrashPurgeTest), so the trash purge
 *   removes the rows with their team. `invited_by` cascades too: an invitation never outlives the
 *   account that issued it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('invited_role', 16);
            $table->binary('token_hash');
            $table->foreignUuid('invited_by')->index()->constrained('users')->cascadeOnDelete();
            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->integer('max_uses');
            $table->integer('use_count')->default(0);
            $table->timestampsTz();

            $table->unique('token_hash');
            $table->index(['team_id', 'created_at']);
        });

        DB::statement('ALTER TABLE team_invitations ADD COLUMN email citext NULL');
        DB::statement('CREATE INDEX team_invitations_team_id_email_index ON team_invitations (team_id, email) WHERE email IS NOT NULL');

        DB::statement("ALTER TABLE team_invitations ADD CONSTRAINT team_invitations_invited_role_check CHECK (invited_role IN ('admin', 'member', 'viewer'))");
        DB::statement('ALTER TABLE team_invitations ADD CONSTRAINT team_invitations_token_hash_check CHECK (octet_length(token_hash) = 32)');
        DB::statement('ALTER TABLE team_invitations ADD CONSTRAINT team_invitations_max_uses_check CHECK ((email IS NOT NULL AND max_uses = 1) OR (email IS NULL AND max_uses = 20))');
        DB::statement("ALTER TABLE team_invitations ADD CONSTRAINT team_invitations_admin_email_check CHECK (invited_role <> 'admin' OR email IS NOT NULL)");
        DB::statement('ALTER TABLE team_invitations ADD CONSTRAINT team_invitations_use_count_check CHECK (use_count >= 0 AND use_count <= max_uses)');
        DB::statement('ALTER TABLE team_invitations ADD CONSTRAINT team_invitations_accepted_check CHECK ((accepted_at IS NOT NULL) = (use_count = max_uses))');
        DB::statement('ALTER TABLE team_invitations ADD CONSTRAINT team_invitations_email_check CHECK (email IS NULL OR (email::text = lower(btrim(email::text)) AND length(email::text) BETWEEN 3 AND 255))');
        DB::statement('ALTER TABLE team_invitations ADD CONSTRAINT team_invitations_expires_check CHECK (expires_at > created_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('team_invitations');
    }
};
