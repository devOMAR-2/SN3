<?php

namespace Database\Factories;

use App\Domain\Attachments\AttachmentStorage;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * Attachments of an existing task, with their stored file: `TaskAttachment::factory()->for($task)
 * ->create()` writes a small text file to the attachments disk (fake it in tests) under a new random
 * key, as UploadAttachment does. `withContent()` sets the file's bytes; size and SHA-256 follow.
 *
 * @extends Factory<TaskAttachment>
 */
class TaskAttachmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),
            'project_id' => fn (array $attributes): string => self::task($attributes)->project_id,
            'team_id' => fn (array $attributes): string => self::task($attributes)->team_id,
            'uploaded_by' => fn (array $attributes): string => self::task($attributes)->project->team->owner_user_id,
            'storage_disk' => fn (): string => app(AttachmentStorage::class)->diskName(),
            'storage_key' => fn (array $attributes): string => app(AttachmentStorage::class)->newKey(self::string($attributes, 'team_id'), self::string($attributes, 'project_id')),
            'original_name' => 'notes.txt',
            'mime_type' => 'text/plain',
            'content' => 'Meeting notes',
        ];
    }

    public function withContent(string $content, string $name = 'notes.txt', string $mimeType = 'text/plain'): static
    {
        return $this->state(['content' => $content, 'original_name' => $name, 'mime_type' => $mimeType]);
    }

    public function by(User $user): static
    {
        return $this->state(['uploaded_by' => $user->id]);
    }

    public function configure(): static
    {
        return $this->afterMaking(function (TaskAttachment $attachment): void {
            $attributes = $attachment->getAttributes();
            $content = is_string($attributes['content'] ?? null) ? $attributes['content'] : '';
            unset($attributes['content']);

            app(AttachmentStorage::class)->disk($attachment->storage_disk)->put($attachment->storage_key, $content);

            $attributes['size_bytes'] = strlen($content);
            $attributes['sha256'] = DB::raw("decode('".hash('sha256', $content)."', 'hex')");
            $attributes['created_at'] ??= now();
            $attachment->setRawAttributes($attributes);
        })->afterCreating(function (TaskAttachment $attachment): void {
            $attachment->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function task(array $attributes): Task
    {
        return Task::withTrashed()->with('project.team')->findOrFail(self::string($attributes, 'task_id'));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function string(array $attributes, string $key): string
    {
        $value = $attributes[$key] ?? null;

        return is_string($value) ? $value : '';
    }
}
