<?php

namespace App\Modules\Shared\Notifications;

use App\Modules\Shared\Models\BaseModel;
use App\Modules\Shared\Scoping\BelongsToScope;
use App\Modules\Shared\Scoping\HasScopeColumns;
use App\Modules\Shared\Scoping\ScopeColumns;

/**
 * Message text per event, channel and language (spec §7.10). Code never
 * builds message text inline (spec §9 rule 1).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $event_key
 * @property NotificationChannel $channel
 * @property string $language
 * @property string|null $subject
 * @property string $body_template
 * @property string|null $provider_template_id
 * @property bool $is_active
 */
final class NotificationTemplate extends BaseModel implements HasScopeColumns
{
    use BelongsToScope;

    /** Mirrors the column defaults, so new models report what the database stores. */
    protected $attributes = ['language' => 'en', 'is_active' => true];

    protected function casts(): array
    {
        return [
            'channel' => NotificationChannel::class,
            'is_active' => 'boolean',
        ];
    }

    public static function scopeColumns(): ScopeColumns
    {
        return new ScopeColumns(visibleToWholeOrganization: true);
    }
}
