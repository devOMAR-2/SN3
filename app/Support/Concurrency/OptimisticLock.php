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
     * @return TModel the fresh, saved copy
     *
     * @throws StaleVersionException
     */
    public static function update(Model $model, int $expectedVersion, Closure $change): Model
    {
        return DB::transaction(function () use ($model, $expectedVersion, $change): Model {
            /** @var TModel $locked */
            $locked = $model->newQuery()->whereKey($model->getKey())->lockForUpdate()->firstOrFail();

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
