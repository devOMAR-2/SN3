<?php

namespace App\Domain\Comments;

use App\Models\Project;
use App\Models\TaskComment;
use App\Support\Tenancy\ProjectAudience;
use Illuminate\Support\Facades\DB;

/**
 * Mentions in comments (API_SPEC.md §6.2 "mention IDs must be explicit server-validated eligible
 * users, not arbitrary free-text matches"; FR-COL-004; CORE-008, ADR-027 §2).
 *
 * - **Syntax:** a mention is `@` followed by a user's UUID (`@018f8c70-…`), as the client inserts it
 *   when someone picks a person. Names are never matched, so `@Sara` is plain text. The token must
 *   not follow a letter, digit, `_` or `@` (an e-mail address is not a mention), and must not run on
 *   into further letters, digits, `_` or `-`.
 * - **Bounded:** at most MAX distinct candidates per comment are considered (the first ones in the
 *   body); the pattern has no nested quantifiers, so parsing is linear in the body's length.
 * - **Eligible:** only users who can see the task's project right now (ProjectPolicy::view): an
 *   active member of the project's team, and for a private project the Team Owner or an explicit
 *   project member. Everyone else (another team's user, a non-member of the private project, a
 *   disabled account, an unknown ID) stays plain text. The answer is the same for all of them, so a
 *   comment reveals nothing about accounts outside the project.
 *
 * Stored mentions are a hook for notifications (CORE-009): they record who was mentioned when the
 * comment was written; notifying must re-check access at send time.
 */
final class CommentMentions
{
    /** Distinct mention candidates considered per comment. */
    public const MAX = 50;

    private const PATTERN = '/(?<![\p{L}\p{N}_@])@([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})(?![\p{L}\p{N}_-])/iu';

    /**
     * The distinct user IDs a body mentions, lower-cased, in order of first appearance.
     *
     * @return list<string>
     */
    public static function candidates(string $body): array
    {
        if (preg_match_all(self::PATTERN, $body, $matches) === false) {
            return [];
        }

        $ids = [];

        foreach ($matches[1] as $id) {
            $ids[strtolower($id)] = true;

            if (count($ids) >= self::MAX) {
                break;
            }
        }

        return array_keys($ids);
    }

    /**
     * The candidates who can see the project, sorted.
     *
     * @param  list<string>  $candidates
     * @return list<string>
     */
    public static function eligible(Project $project, array $candidates): array
    {
        return ProjectAudience::filter($project->id, $candidates);
    }

    /**
     * Replaces the comment's stored mentions with `$userIds`.
     *
     * @param  list<string>  $userIds  eligible users (self::eligible())
     * @return list<string> the users who were not mentioned before
     */
    public static function sync(TaskComment $comment, array $userIds): array
    {
        $current = [];

        foreach (DB::table('task_comment_mentions')->where('comment_id', $comment->id)->pluck('user_id') as $id) {
            if (is_string($id)) {
                $current[] = $id;
            }
        }

        $added = array_values(array_diff($userIds, $current));
        $removed = array_values(array_diff($current, $userIds));

        if ($removed !== []) {
            DB::table('task_comment_mentions')->where('comment_id', $comment->id)->whereIn('user_id', $removed)->delete();
        }

        if ($added !== []) {
            DB::table('task_comment_mentions')->insert(array_map(fn (string $userId): array => [
                'comment_id' => $comment->id,
                'user_id' => $userId,
                'created_at' => now(),
            ], $added));
        }

        return $added;
    }

    /**
     * The comment's stored mentions, sorted.
     *
     * @return list<string>
     */
    public static function of(TaskComment $comment): array
    {
        $ids = [];

        foreach (DB::table('task_comment_mentions')->where('comment_id', $comment->id)->orderBy('user_id')->pluck('user_id') as $id) {
            if (is_string($id)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
