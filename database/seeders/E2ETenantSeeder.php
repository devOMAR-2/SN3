<?php

namespace Database\Seeders;

use App\Domain\Activity\Activity;
use App\Domain\Attachments\AttachmentStorage;
use App\Domain\Projects\ProjectRole;
use App\Domain\Projects\ProjectVisibility;
use App\Domain\Teams\TeamRole;
use App\Domain\Workflows\StatusCategory;
use App\Models\Label;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\ProjectStatus;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\TaskChecklistItem;
use App\Models\TaskComment;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Deterministic test tenants for end-to-end and isolation tests (FND-008, docs/TESTING.md).
 *
 * Reads e2e/fixtures/tenants.json, the single source shared with the Playwright suite: two
 * unrelated teams with every role, one user in both teams and one in none, all with fixed UUIDs
 * and verified emails. Run it on an empty database:
 *
 *     php artisan migrate:fresh --seed --seeder="Database\Seeders\E2ETenantSeeder"
 *
 * The fixture password is public, so the seeder refuses to run outside local, testing and e2e.
 */
class E2ETenantSeeder extends Seeder
{
    public const FIXTURE = 'e2e/fixtures/tenants.json';

    public const ENVIRONMENTS = ['local', 'testing', 'e2e'];

    public function run(): void
    {
        if (! app()->environment(self::ENVIRONMENTS)) {
            throw new RuntimeException('E2ETenantSeeder only runs in the '.implode(', ', self::ENVIRONMENTS).' environments.');
        }

        $fixture = self::fixture();
        $password = Hash::make($fixture['password']);

        DB::transaction(function () use ($fixture, $password): void {
            $users = [];

            // Users in no seeded team, for E2E flows that change data: `workflow_users` (invitations,
            // CORE-002) and `journey_users` (the CORE-006 journey). The read-only specs never see their writes.
            foreach ([...$fixture['users'], ...($fixture['workflow_users'] ?? []), ...($fixture['journey_users'] ?? [])] as $data) {
                $user = new User;
                $user->forceFill([
                    'id' => $data['id'],
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'locale' => $data['locale'],
                    'password' => $password,
                    'email_verified_at' => now(),
                ])->save();

                $users[$data['key']] = $user;
            }

            foreach ($fixture['teams'] as $data) {
                $team = new Team;
                $team->forceFill([
                    'id' => $data['id'],
                    'name' => $data['name'],
                    'slug' => $data['slug'],
                    'owner_user_id' => $users[$data['owner']]->id,
                ])->save();

                $roles = [$data['owner'] => TeamRole::Owner->value, ...$data['members']];

                foreach ($roles as $userKey => $role) {
                    (new TeamMembership)->forceFill([
                        'team_id' => $team->id,
                        'user_id' => $users[$userKey]->id,
                        'role' => TeamRole::from($role),
                        'joined_at' => now(),
                    ])->save();
                }
            }
        });

        $this->seedProjects($fixture['users'], $fixture['teams'], $fixture['projects'] ?? []);
    }

    /**
     * The `projects` of the fixture (CORE-011): per project its members, an extra status, a task assigned as
     * listed, a label on it, a checklist item, a comment mentioning the listed users, an attachment and inbox
     * notifications, all with fixed IDs. Every text carries the project's canary, so the isolation suite can
     * assert that a response holds no canary its viewer may not see.
     *
     * @param  list<array{key: string, id: string}>  $userRows
     * @param  list<array{key: string, id: string}>  $teamRows
     * @param  list<array{key: string, team: string, project_key: string, visibility: string, canary: string, members: array<string, string>, ids: array<string, string>, assignee: string|null, mentions: list<string>, notifications: list<array{to: string, type: string, actor: string, id: string}>}>  $projects
     */
    private function seedProjects(array $userRows, array $teamRows, array $projects): void
    {
        $users = [];
        foreach ($userRows as $row) {
            $users[$row['key']] = $row['id'];
        }

        $teams = [];
        foreach ($teamRows as $row) {
            $teams[$row['key']] = $row['id'];
        }

        foreach ($projects as $data) {
            DB::transaction(function () use ($data, $users, $teams): void {
                $canary = $data['canary'];
                $ids = $data['ids'];
                $teamId = $teams[$data['team']];
                $owner = Team::query()->whereKey($teamId)->firstOrFail()->owner_user_id;

                $project = Project::factory()->withDefaultStatuses()->create([
                    'id' => $ids['project'],
                    'team_id' => $teamId,
                    'name' => "Project {$canary}",
                    'description' => "Description {$canary}",
                    'project_key' => $data['project_key'],
                    'visibility' => ProjectVisibility::from($data['visibility']),
                ]);

                foreach ($data['members'] as $userKey => $role) {
                    ProjectMembership::factory()->for($project)->member(User::query()->whereKey($users[$userKey])->firstOrFail())->role(ProjectRole::from($role))->create();
                }

                $status = new ProjectStatus;
                $status->forceFill([
                    'id' => $ids['status'],
                    'project_id' => $project->id,
                    'team_id' => $teamId,
                    'name' => "Status {$canary}",
                    'category' => StatusCategory::InProgress,
                    'position' => $project->statuses()->count(),
                ])->save();

                $task = Task::factory()->create([
                    'id' => $ids['task'],
                    'project_id' => $project->id,
                    'title' => "Task {$canary}",
                    'description' => "Task description {$canary}",
                    'assignee_user_id' => $data['assignee'] === null ? null : $users[$data['assignee']],
                ]);

                $label = Label::factory()->for($project)->create(['id' => $ids['label'], 'name' => "Label {$canary}"]);
                DB::table('task_labels')->insert(['task_id' => $task->id, 'project_id' => $project->id, 'label_id' => $label->id]);

                TaskChecklistItem::factory()->for($task)->create(['id' => $ids['checklist_item'], 'content' => "Checklist {$canary}"]);

                $comment = TaskComment::factory()->for($task)->create([
                    'id' => $ids['comment'],
                    'author_user_id' => $owner,
                    'body' => "Comment {$canary}",
                ]);

                foreach ($data['mentions'] as $userKey) {
                    DB::table('task_comment_mentions')->insert(['comment_id' => $comment->id, 'user_id' => $users[$userKey], 'created_at' => now()]);
                }

                TaskAttachment::factory()->for($task)->withContent("File content {$canary}", "file-{$canary}.txt", 'text/plain')
                    // A fixed key (the attachment ID), so a rerun overwrites the file instead of adding one.
                    ->create([
                        'id' => $ids['attachment'],
                        'storage_key' => app(AttachmentStorage::class)->projectPrefix($teamId, $project->id).'/'.str_replace('-', '', $ids['attachment']),
                    ]);

                Activity::task($task, User::query()->whereKey($owner)->firstOrFail(), 'task.created', ['status_id' => $task->status_id]);

                foreach ($data['notifications'] as $note) {
                    $mention = $note['type'] === 'comment.mentioned';
                    DB::table('notifications')->insert([
                        'id' => $note['id'],
                        'recipient_user_id' => $users[$note['to']],
                        'team_id' => $teamId,
                        'project_id' => $project->id,
                        'task_id' => $task->id,
                        'comment_id' => $mention ? $comment->id : null,
                        'actor_user_id' => $users[$note['actor']],
                        'type' => $note['type'],
                        'payload' => '{}',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });
        }
    }

    /**
     * @return array{password: string, users: list<array{key: string, id: string, name: string, email: string, locale: string}>, workflow_users?: list<array{key: string, id: string, name: string, email: string, locale: string}>, journey_users?: list<array{key: string, id: string, name: string, email: string, locale: string}>, teams: list<array{key: string, id: string, name: string, slug: string, owner: string, members: array<string, string>}>, projects?: list<array{key: string, team: string, project_key: string, visibility: string, canary: string, members: array<string, string>, ids: array<string, string>, assignee: string|null, mentions: list<string>, notifications: list<array{to: string, type: string, actor: string, id: string}>}>}
     */
    public static function fixture(): array
    {
        $json = file_get_contents(base_path(self::FIXTURE));

        if ($json === false) {
            throw new RuntimeException('Cannot read '.self::FIXTURE.'.');
        }

        /** @var array{password: string, users: list<array{key: string, id: string, name: string, email: string, locale: string}>, workflow_users?: list<array{key: string, id: string, name: string, email: string, locale: string}>, journey_users?: list<array{key: string, id: string, name: string, email: string, locale: string}>, teams: list<array{key: string, id: string, name: string, slug: string, owner: string, members: array<string, string>}>, projects?: list<array{key: string, team: string, project_key: string, visibility: string, canary: string, members: array<string, string>, ids: array<string, string>, assignee: string|null, mentions: list<string>, notifications: list<array{to: string, type: string, actor: string, id: string}>}>} $fixture */
        $fixture = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return $fixture;
    }
}
