<?php

namespace App\Http\Concurrency;

use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionRequiredHttpException;

/**
 * Reads the `If-Match: "<version>"` precondition of versioned mutations (API_SPEC.md §1.4).
 * Missing: 428 `precondition_required`. A value that cannot be a version can never match: 412.
 */
final class IfMatch
{
    public static function version(Request $request): int
    {
        $header = $request->headers->get('If-Match');

        if ($header === null || trim($header) === '') {
            throw new PreconditionRequiredHttpException;
        }

        if (preg_match('/^"([1-9][0-9]{0,17})"$/', trim($header), $matches) !== 1) {
            throw new PreconditionFailedHttpException;
        }

        return (int) $matches[1];
    }
}
