<?php

namespace App\Domain\Collaboration;

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Who may change content someone authored in a project: comments (API_SPEC.md §6.2) and attachments
 * (§6.3). CORE-008, ADR-027 §1.
 *
 * - **Edit** (comments only): the author, while they may still write in the project (`contribute`):
 *   a Team Viewer, or a member removed from a private project, edits nothing.
 * - **Delete:** the author under the same condition, or a moderator (`ProjectPolicy::moderate`: the
 *   Team Owner or a project manager). A Team Admin without the `manager` role moderates nothing.
 *
 * Callers resolve the project through AccessibleProjects first, so these checks only ever concern a
 * project the user can see (403, never a disclosure).
 */
final class AuthoredContent
{
    public static function mayEdit(User $user, Project $project, string $authorId): bool
    {
        return $authorId === $user->id && Gate::forUser($user)->allows('contribute', $project);
    }

    public static function mayDelete(User $user, Project $project, string $authorId): bool
    {
        return self::mayEdit($user, $project, $authorId) || self::moderates($user, $project, $authorId);
    }

    /** True when the user deletes someone else's content as a moderator. */
    public static function moderates(User $user, Project $project, string $authorId): bool
    {
        return $authorId !== $user->id && Gate::forUser($user)->allows('moderate', $project);
    }
}
