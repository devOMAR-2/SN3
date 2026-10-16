<?php

namespace App\Support\Exceptions;

use RuntimeException;

/**
 * The resource is read-only right now, e.g. an archived team (API_SPEC.md §1.3: 423
 * `resource_locked`, ARCHITECTURE.md §5.2 step 6).
 */
final class ResourceLockedException extends RuntimeException {}
