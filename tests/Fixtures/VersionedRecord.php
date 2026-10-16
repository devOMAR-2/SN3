<?php

namespace Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Stand-in for a versioned resource (projects, tasks, …) until those tables exist.
 * Its table is created inside the test transaction by OptimisticLockTest.
 *
 * @property string $id
 * @property string $title
 * @property int $version
 */
class VersionedRecord extends Model
{
    use HasUuids;

    protected $table = 'versioned_records';

    /**
     * @var list<string>
     */
    protected $fillable = ['title'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['version' => 'integer'];
    }
}
