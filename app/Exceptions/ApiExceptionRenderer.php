<?php

namespace App\Exceptions;

use App\Http\Middleware\SetLocale;
use App\Http\Responses\ApiResponse;
use App\Support\Concurrency\StaleVersionException;
use App\Support\Exceptions\DomainConflictException;
use App\Support\Exceptions\ResourceLockedException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Renders every /api/v1 failure as the API_SPEC.md §1.2 error envelope with the §1.3 code.
 *
 * Messages are translated, generic and never include exception text, SQL, stack traces or
 * whether a hidden resource exists.
 */
final class ApiExceptionRenderer
{
    /** @var array<int, string> */
    private const CODES = [
        400 => 'bad_request',
        401 => 'unauthenticated',
        403 => 'forbidden',
        404 => 'not_found',
        405 => 'method_not_allowed',
        409 => 'conflict',
        410 => 'expired',
        412 => 'version_mismatch',
        413 => 'payload_too_large',
        419 => 'csrf_invalid',
        422 => 'validation_failed',
        423 => 'resource_locked',
        428 => 'precondition_required',
        429 => 'rate_limited',
        503 => 'service_unavailable',
    ];

    public static function handles(Request $request): bool
    {
        return $request->is('api/*');
    }

    public static function render(Throwable $e, Request $request): ?JsonResponse
    {
        if (! self::handles($request)) {
            return null;
        }

        // No route matched (e.g. an unknown path), so the web group and SetLocale never ran.
        if ($request->route() === null) {
            app()->setLocale(app(SetLocale::class)->resolve($request)->value);
        }

        if ($e instanceof ValidationException) {
            return ApiResponse::error('validation_failed', 422, self::fields($e));
        }

        if ($e instanceof StaleVersionException) {
            return ApiResponse::error('version_mismatch', 412);
        }

        if ($e instanceof DomainConflictException) {
            return ApiResponse::error('conflict', 409);
        }

        if ($e instanceof ResourceLockedException) {
            return ApiResponse::error('resource_locked', 423);
        }

        if ($e instanceof AuthenticationException) {
            return ApiResponse::error('unauthenticated', 401);
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $code = self::CODES[$status] ?? ($status >= 500 ? 'server_error' : 'bad_request');

            return ApiResponse::error($code, $status, null, $e->getHeaders());
        }

        return ApiResponse::error('server_error', 500);
    }

    /**
     * Field paths mapped to stable rule codes, e.g. {"email": ["required"]}.
     *
     * Rules come from the validator's failed() map (rule names or rule classes in snake_case).
     * Errors added without a rule (withMessages / after-hooks) already carry a code as message.
     *
     * @return array<string, list<string>>
     */
    private static function fields(ValidationException $e): array
    {
        $failed = $e->validator->failed();
        $fields = [];

        foreach ($e->errors() as $field => $messages) {
            $codes = isset($failed[$field])
                ? array_map(fn (int|string $rule): string => self::ruleCode((string) $rule), array_keys($failed[$field]))
                : array_values($messages);

            $fields[$field] = array_values(array_unique($codes));
        }

        return $fields;
    }

    private static function ruleCode(string $rule): string
    {
        return Str::snake(class_exists($rule) ? class_basename($rule) : $rule);
    }
}
