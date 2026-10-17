<?php

namespace App\Models;

use App\Domain\Notifications\NotificationType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One inbox notification (DATABASE.md §8, API_SPEC.md §9; CORE-009, ADR-029). Reach notifications only
 * through App\Domain\Notifications\Inbox, which shows the recipient only those about things they can
 * see right now; nothing here is ever rendered from a cached copy: titles and names are read from the
 * live rows (`payload` holds field names only).
 *
 * @property string $id
 * @property string $recipient_user_id
 * @property string $team_id
 * @property string|null $project_id
 * @property string|null $task_id
 * @property string|null $comment_id
 * @property string|null $invitation_id
 * @property string|null $actor_user_id
 * @property NotificationType $type
 * @property array<string, mixed> $payload
 * @property int $event_count
 * @property Carbon|null $read_at
 * @property Carbon|null $email_due_at
 * @property Carbon|null $emailed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class InboxNotification extends Model
{
    use HasUuids;

    protected $table = 'notifications';

    /**
     * Written by App\Domain\Notifications only.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => NotificationType::class,
            'payload' => 'array',
            'event_count' => 'integer',
            'read_at' => 'datetime',
            'email_due_at' => 'datetime',
            'emailed_at' => 'datetime',
        ];
    }

    /**
     * The changed task fields of a `task.updated` notification.
     *
     * @return list<string>
     */
    public function fields(): array
    {
        $stored = $this->payload['fields'] ?? [];
        $fields = [];

        foreach (is_array($stored) ? $stored : [] as $field) {
            if (is_string($field)) {
                $fields[] = $field;
            }
        }

        return $fields;
    }
}
