<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `user_preferences` (DATABASE.md §8). FND-004 uses `theme`; notification preferences are
 * consumed by CORE-009. Locale and timezone stay on `users`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_preferences', function (Blueprint $table) {
            $table->foreignUuid('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->boolean('email_notifications')->default(true);
            $table->boolean('in_app_notifications')->default(true);
            $table->string('theme', 16)->default('system');
            $table->jsonb('settings')->default('{}');
            $table->timestampTz('updated_at')->nullable();
        });

        DB::statement("ALTER TABLE user_preferences ADD CONSTRAINT user_preferences_theme_check CHECK (theme IN ('system', 'light', 'dark'))");
        DB::statement("ALTER TABLE user_preferences ADD CONSTRAINT user_preferences_settings_object_check CHECK (jsonb_typeof(settings) = 'object')");
    }

    public function down(): void
    {
        Schema::dropIfExists('user_preferences');
    }
};
