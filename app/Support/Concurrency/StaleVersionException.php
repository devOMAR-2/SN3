<?php

namespace App\Support\Concurrency;

use RuntimeException;

/**
 * The client's expected `version` no longer matches the stored row. Rendered as
 * 412 `version_mismatch` (API_SPEC.md §1.4); nothing was changed.
 */
final class StaleVersionException extends RuntimeException {}
