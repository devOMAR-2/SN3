<?php

return [

    // Generic, safe messages for API_SPEC.md §1.3 error codes. Never mention whether a hidden resource exists.
    'errors' => [
        'bad_request' => 'The request could not be understood.',
        'unauthenticated' => 'Sign in to continue.',
        'forbidden' => 'You do not have permission to do this.',
        'not_found' => 'The requested resource was not found.',
        'method_not_allowed' => 'This action is not supported here.',
        'conflict' => 'The request conflicts with the current state.',
        'expired' => 'This link or item has expired.',
        'version_mismatch' => 'This item was changed by someone else. Reload and try again.',
        'payload_too_large' => 'The upload is too large.',
        'csrf_invalid' => 'Your session has expired. Reload the page and try again.',
        'validation_failed' => 'The provided data is invalid.',
        'resource_locked' => 'This item is read-only right now.',
        'precondition_required' => 'This request needs a version precondition.',
        'rate_limited' => 'Too many requests. Wait a moment and try again.',
        'service_unavailable' => 'A dependency is temporarily unavailable. Try again shortly.',
        'server_error' => 'Something went wrong. Try again later.',
    ],

];
