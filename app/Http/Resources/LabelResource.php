<?php

namespace App\Http\Resources;

use App\Models\Label;

/**
 * A project label (API_SPEC.md §5, ADR-023 §6).
 */
final class LabelResource
{
    /**
     * @return array{id: string, project_id: string, name: string, color: string|null, created_at: string|null, updated_at: string|null}
     */
    public static function make(Label $label): array
    {
        return [
            'id' => $label->id,
            'project_id' => $label->project_id,
            'name' => $label->name,
            'color' => $label->color,
            'created_at' => $label->created_at?->utc()->toIso8601ZuluString(),
            'updated_at' => $label->updated_at?->utc()->toIso8601ZuluString(),
        ];
    }
}
