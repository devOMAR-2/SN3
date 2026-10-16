<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The stored result of a successful `Idempotency-Key` request (DATABASE.md §20), written only by
 * App\Http\Middleware\EnforceIdempotency. Pruned daily once expired (24 hours).
 *
 * @property string $id
 * @property string $actor_user_id
 * @property string|null $team_id
 * @property string $route_key
 * @property string $idempotency_key
 * @property string $request_digest
 * @property int $response_status
 * @property array<array-key, mixed> $response_json
 * @property array<string, string>|null $response_headers
 * @property Carbon $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ApiIdempotencyRecord extends Model
{
    use HasUuids, MassPrunable;

    public const TTL_HOURS = 24;

    /**
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'response_status' => 'integer',
            'response_json' => 'array',
            'response_headers' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<=', now());
    }
}
