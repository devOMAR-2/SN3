<?php

namespace App\Support\Concurrency;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Version-checked updates (API_SPEC.md §1.4, DATABASE.md §20): lock the row, compare its
 * `version` with the client's If-Match value, apply the change and increment `version` in one
 * transaction. A mismatch changes nothing.
 */
final class OptimisticLock
{
    /**
     * @template TModel of Model
     *
     * @param  TModel  $model  a model with an integer-cast `version` attribute
     * @param  Closure(TModel): void  $change  sets attributes on the locked, fresh copy
     * @param  (Closure(TModel): void)|null  $guard  state checks on the locked copy that run before the
     *                                               version comparison, so e.g. 423 wins over 412
     * @param  string  $lock  row-lock clause; models with children referencing them should pass
     *                        `for no key update` so child inserts are not blocked
     * @return TModel the fresh, saved copy
     *
     * @throws StaleVersionException
     */
    public static function update(Model $model, int $expectedVersion, Closure $change, ?Closure $guard = null, string $lock = 'for update'): Model
    {
        return DB::transaction(function () use ($model, $expectedVersion, $change, $guard, $lock): Model {
            /** @var TModel $locked */
            $locked = $model->newQuery()->whereKey($model->getKey())->lock($lock)->firstOrFail();

            if ($guard !== null) {
                $guard($locked);
            }

            if ($locked->getAttribute('version') !== $expectedVersion) {
                throw new StaleVersionException;
            }

            $change($locked);

            // An update that changes nothing is still successful but keeps the version.
            if ($locked->isDirty()) {
                $locked->setAttribute('version', $expectedVersion + 1);
                $locked->save();
            }

            return $locked;
        });
    }
}
