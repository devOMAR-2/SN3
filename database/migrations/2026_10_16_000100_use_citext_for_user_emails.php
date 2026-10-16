<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DATABASE.md §20: case-insensitive email columns use the PostgreSQL `citext` extension
 * (no alternative schema branch). `users.email` moves from varchar + unique lower(email) index
 * to citext with a plain unique index, which is case-insensitive by type.
 *
 * `citext` ships with PostgreSQL and is a trusted extension (PostgreSQL 13+), so the database
 * owner can create it without superuser rights.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS citext');
        DB::statement('ALTER TABLE users ALTER COLUMN email TYPE citext');
        DB::statement('DROP INDEX IF EXISTS users_email_lower_unique');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users ALTER COLUMN email TYPE varchar(255)');
        DB::statement('CREATE UNIQUE INDEX users_email_lower_unique ON users (lower(email))');
        // The extension stays: other objects may depend on it, and it is harmless when unused.
    }
};
