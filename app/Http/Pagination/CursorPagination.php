<?php

namespace App\Http\Pagination;

use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use UnexpectedValueException;

/**
 * Cursor pagination for /api/v1 lists (API_SPEC.md §1.1, §1.4): `limit` 1–100 (default 25),
 * opaque signed `cursor` valid for 24 hours and bound to the endpoint path.
 *
 * The caller's query must already be authorization-scoped and ordered with a unique final key
 * (e.g. `created_at desc, id desc`), so pages are stable.
 */
final class CursorPagination
{
    public const DEFAULT_LIMIT = 25;

    public const MAX_LIMIT = 100;

    public const TTL_SECONDS = 86_400;

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  Closure(TModel): mixed  $transform
     * @param  array<string, mixed>  $meta  extra collection meta, e.g. a version the list was read at
     * @param  string|null  $scope  what else the cursor is bound to besides the endpoint path, e.g. the
     *                              list's sort order: a cursor only fits the order it was issued for
     */
    public static function respond(Request $request, Builder $query, Closure $transform, array $meta = [], ?string $scope = null): JsonResponse
    {
        return self::respondMany(
            $request,
            $query,
            static fn (array $items): array => array_values(array_map($transform, $items)),
            $meta,
            $scope,
        );
    }

    /**
     * Like respond(), with one transform for the whole page, for representations that load related
     * rows in one query per page (e.g. the inbox, CORE-009).
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  Closure(list<TModel>): list<mixed>  $transform
     * @param  array<string, mixed>  $meta
     */
    public static function respondMany(Request $request, Builder $query, Closure $transform, array $meta = [], ?string $scope = null): JsonResponse
    {
        Validator::make($request->query(), [
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
            'cursor' => ['sometimes', 'string', 'max:2048'],
        ])->validate();

        $limit = $request->integer('limit', self::DEFAULT_LIMIT);
        $scope = $scope === null ? $request->path() : $request->path().'|'.$scope;
        $token = $request->query('cursor');
        $cursor = is_string($token) ? self::decode($token, $scope) : null;

        try {
            $page = $query->cursorPaginate($limit, ['*'], 'cursor', $cursor);
        } catch (UnexpectedValueException $e) {
            // A validly signed cursor whose keys do not fit this query's order: a client error, not a 500.
            if ($cursor === null) {
                throw $e;
            }

            throw new BadRequestHttpException('Invalid cursor.', $e);
        }
        $next = $page->nextCursor();

        /** @var list<TModel> $items */
        $items = array_values($page->items());

        return ApiResponse::collection(
            $transform($items),
            [
                'next_cursor' => $next === null ? null : self::encode($next, $scope),
                'has_more' => $page->hasMorePages(),
                'limit' => $limit,
                ...$meta,
            ],
        );
    }

    public static function encode(Cursor $cursor, string $scope, ?int $now = null): string
    {
        $payload = self::base64UrlEncode((string) json_encode([
            'c' => $cursor->encode(),
            'e' => ($now ?? time()) + self::TTL_SECONDS,
            's' => $scope,
        ]));

        return $payload.'.'.self::signature($payload);
    }

    /**
     * @throws BadRequestHttpException for a tampered, expired, foreign or malformed cursor
     */
    public static function decode(string $token, string $scope, ?int $now = null): Cursor
    {
        $parts = explode('.', $token);

        if (count($parts) !== 2 || ! hash_equals(self::signature($parts[0]), $parts[1])) {
            throw new BadRequestHttpException('Invalid cursor.');
        }

        $data = json_decode((string) self::base64UrlDecode($parts[0]), true);

        if (! is_array($data)) {
            throw new BadRequestHttpException('Invalid cursor.');
        }

        $encoded = $data['c'] ?? null;
        $expires = $data['e'] ?? null;

        if (! is_string($encoded) || ! is_int($expires) || ($data['s'] ?? null) !== $scope) {
            throw new BadRequestHttpException('Invalid cursor.');
        }

        if ($expires < ($now ?? time())) {
            throw new BadRequestHttpException('Expired cursor.');
        }

        return Cursor::fromEncoded($encoded) ?? throw new BadRequestHttpException('Invalid cursor.');
    }

    private static function signature(string $payload): string
    {
        $key = config()->string('app.key');

        return self::base64UrlEncode(hash_hmac('sha256', 'sn3.cursor|'.$payload, $key, true));
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string|false
    {
        return base64_decode(strtr($value, '-_', '+/'), true);
    }
}
