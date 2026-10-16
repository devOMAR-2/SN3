<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $user_id
 * @property bool $email_notifications
 * @property bool $in_app_notifications
 * @property string $theme
 * @property array<string, mixed> $settings
 * @property Carbon|null $updated_at
 */
class UserPreference extends Model
{
    public const DEFAULT_THEME = 'system';

    /** @var list<string> */
    public const THEMES = ['system', 'light', 'dark'];

    public const CREATED_AT = null;

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'theme',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'email_notifications' => true,
        'in_app_notifications' => true,
        'theme' => self::DEFAULT_THEME,
        'settings' => '{}',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_notifications' => 'boolean',
            'in_app_notifications' => 'boolean',
            'settings' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
