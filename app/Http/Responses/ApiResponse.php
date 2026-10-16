<?php

namespace App\Http\Responses;

use App\Http\Middleware\AssignRequestId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Standard /api/v1 envelopes (API_SPEC.md §1.2).
 */
final class ApiResponse
{
    /**
     * @param  array<string, string>  $headers
     */
    public static function data(mixed $data, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse(
            ['data' => $data, 'meta' => ['request_id' => self::requestId()]],
            $status,
            $headers,
        );
    }

    /**
     * @param  array<string, list<string>>|null  $fields
     * @param  array<string, mixed>  $headers
     */
    public static function error(string $code, int $status, ?array $fields = null, array $headers = []): JsonResponse
    {
        $error = [
            'code' => $code,
            'message' => self::message($code),
        ];

        if ($fields !== null) {
            $error['fields'] = $fields;
        }

        $error['request_id'] = self::requestId();

        return new JsonResponse(['error' => $error], $status, $headers);
    }

    public static function message(string $code): string
    {
        $key = "api.errors.{$code}";
        $message = __($key);

        return is_string($message) && $message !== $key ? $message : (string) __('api.errors.server_error');
    }

    public static function requestId(?Request $request = null): string
    {
        $request ??= request();
        $id = $request->attributes->get(AssignRequestId::ATTRIBUTE);

        if (is_string($id) && $id !== '') {
            return $id;
        }

        // Only reached if the request never passed AssignRequestId (it is global middleware).
        $id = 'req_'.Str::ulid();
        $request->attributes->set(AssignRequestId::ATTRIBUTE, $id);

        return $id;
    }
}
