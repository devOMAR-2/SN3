<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateMeRequest;
use App\Http\Resources\MeResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/me and PATCH /api/v1/me (API_SPEC.md §2), including the notification preferences
 * (CORE-009, ADR-029 §5).
 */
class MeController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return ApiResponse::data(MeResource::make($user));
    }

    public function update(UpdateMeRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validated();

        DB::transaction(function () use ($user, $validated): void {
            $user->fill(Arr::only($validated, ['name', 'locale', 'timezone']));

            if ($user->isDirty()) {
                $user->save();
            }

            $preferences = Arr::only($validated, UpdateMeRequest::PREFERENCE_FIELDS);

            if ($preferences !== []) {
                $user->preferences()->updateOrCreate([], $preferences);
            }
        });

        return ApiResponse::data(MeResource::make($user->refresh()));
    }
}
