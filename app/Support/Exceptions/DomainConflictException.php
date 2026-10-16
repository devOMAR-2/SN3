<?php

namespace App\Support\Exceptions;

use RuntimeException;

/**
 * The request is valid but conflicts with the current state (API_SPEC.md §1.3: 409 `conflict`),
 * e.g. the Owner leaving without a transfer or a slug already taken. The message is for logs only;
 * the response carries the generic translated message.
 */
final class DomainConflictException extends RuntimeException {}
