<?php

namespace App\Domain\Tasks;

/**
 * Task priority (API_SPEC.md §6.1, DATABASE.md §5): `none` (the default) through `urgent`.
 * The database stores the value and derives `priority_rank` (0–4) from it for sorting.
 */
enum TaskPriority: string
{
    case None = 'none';
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Urgent = 'urgent';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
