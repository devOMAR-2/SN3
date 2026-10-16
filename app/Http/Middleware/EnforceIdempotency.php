<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use App\Models\ApiIdempotencyRecord;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * `Idempotency-Key` for retry-safe creates (API_SPEC.md §1.4, DATABASE.md §20). Route alias:
 * `idempotent`; place it after `auth` and any route throttles.
 *
 * - The key must be a UUID; a missing or malformed key is 400 `bad_request`.
 * - Scope: actor + method and path (which carries the team and parent IDs) + key.
 * - The request runs in one transaction with a transaction-scoped advisory lock on that scope, so a
 *   concurrent duplicate waits and then replays the committed result instead of running twice.
 * - Only successful (2xx) results are stored; anything else rolls the whole request back and
 *   leaves the key unused, so the client can fix the request and retry with the same key.
 * - A stored key with the same payload replays the stored status, body and allowlisted headers
 *   (`ETag`, `Location`), with `Idempotent-Replayed: true`; a different payload is 409
 *   `idempotency_key_reused`.
 *
 * Jobs dispatched by these routes should use ->afterCommit() so a queue other than the database
 * driver cannot run them before the request commits.
 */
final class EnforceIdempotency
{
    public const HEADER = 'Idempotency-Key';

    /** Response headers stored with the result and replayed with it (e.g. a created task's version). */
    private const REPLAYED_HEADERS = ['ETag', 'Location'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $key = $request->headers->get(self::HEADER);

        if ($key === null || ! Str::isUuid($key)) {
            return ApiResponse::error('bad_request', 400);
        }

        $key = strtolower($key);
        // Lower-cased so the same UUID written in another case is the same scope.
        $routeKey = $request->method().' /'.strtolower(ltrim($request->path(), '/'));
        $digest = self::digest($request);

        DB::beginTransaction();

        try {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$user->id.'|'.$routeKey.'|'.$key]);

            $stored = ApiIdempotencyRecord::query()
                ->where('actor_user_id', $user->id)
                ->where('route_key', $routeKey)
                ->where('idempotency_key', $key)
                ->first();

            if ($stored !== null && $stored->isExpired()) {
                $stored->delete();
                $stored = null;
            }

            if ($stored !== null) {
                DB::commit();

                return hash_equals($stored->request_digest, $digest)
                    ? self::replay($stored)
                    : ApiResponse::error('idempotency_key_reused', 409);
            }

            $response = $next($request);

            if (! $response->isSuccessful()) {
                DB::rollBack();

                return $response;
            }

            $record = new ApiIdempotencyRecord;
            $record->forceFill([
                'actor_user_id' => $user->id,
                'team_id' => self::teamId($request),
                'route_key' => $routeKey,
                'idempotency_key' => $key,
                'request_digest' => $digest,
                'response_status' => $response->getStatusCode(),
                // A successful response without a JSON body (204, or 202 without content) replays empty.
                'response_json' => $response instanceof JsonResponse ? $response->getData(true) : [],
                'response_headers' => self::storedHeaders($response),
                'expires_at' => now()->addHours(ApiIdempotencyRecord::TTL_HOURS),
            ])->save();

            DB::commit();

            return $response;
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }
    }

    private static function replay(ApiIdempotencyRecord $stored): Response
    {
        $headers = [...($stored->response_headers ?? []), 'Idempotent-Replayed' => 'true'];

        return $stored->response_status === Response::HTTP_NO_CONTENT
            ? new Response('', Response::HTTP_NO_CONTENT, $headers)
            : new JsonResponse($stored->response_json, $stored->response_status, $headers);
    }

    /**
     * @return array<string, string>|null
     */
    private static function storedHeaders(Response $response): ?array
    {
        $headers = [];

        foreach (self::REPLAYED_HEADERS as $name) {
            $value = $response->headers->get($name);

            if (is_string($value) && $value !== '') {
                $headers[$name] = $value;
            }
        }

        return $headers === [] ? null : $headers;
    }

    /**
     * SHA-256 of the canonicalised query and body: key order and JSON whitespace do not matter.
     */
    private static function digest(Request $request): string
    {
        $body = $request->isJson() ? $request->json()->all() : $request->post();

        return hash('sha256', (string) json_encode(
            ['query' => self::canonical($request->query()), 'body' => self::canonical($body)],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::canonical(...), $value);
    }

    private static function teamId(Request $request): ?string
    {
        $team = $request->route('team');

        if ($team instanceof Team) {
            return $team->id;
        }

        // Project-scoped creates (tasks, CORE-005): the record goes with the project's team.
        $project = $request->route('project');

        return $project instanceof Project ? $project->team_id : null;
    }
}
