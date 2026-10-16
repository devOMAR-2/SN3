<?php

use App\Models\UserPreference;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

// Forbids debugging helpers (dd, dump, var_dump, ray, die, exit, ...) and deprecated PHP functions in app code.
arch()->preset()->php();

// Forbids insecure functions (eval, md5, sha1, rand, unserialize, shell_exec, ...) in app code.
arch()->preset()->security();

// UUIDv7 primary keys for every domain entity (DATABASE.md §1). UserPreference is keyed by user_id.
arch('models use UUIDv7 keys')
    ->expect('App\Models')
    ->toUseTrait(HasUuids::class)
    ->ignoring(UserPreference::class);
