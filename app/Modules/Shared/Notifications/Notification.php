<?php

namespace App\Modules\Shared\Notifications;

use App\Modules\Shared\Scoping\BelongsToScope;
use App\Modules\Shared\Scoping\HasScopeColumns;
use App\Modules\Shared\Scoping\ScopeColumns;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One send attempt on one channel (spec §7.10). `organization_id` is an
 * addition to the spec so the log can be scoped.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $recipient_type
 * @property string $recipient_id
 * @property string $template_id
 * @property NotificationChannel $channel
 * @property string $destination
 * @property array<string, string> $payload
 * @property string|null $provider_message_id
 * @property NotificationStatus $status
 * @property string|null $error
 * @property CarbonImmutable|null $sent_at
 * @property NotificationTemplate $template
 */
final class Notification extends Model implements HasScopeColumns
{
    use BelongsToScope;
    use HasUuids;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'channel' => NotificationChannel::class,
            'payload' => 'array',
            'status' => NotificationStatus::class,
            'sent_at' => 'immutable_datetime',
        ];
    }

    public static function scopeColumns(): ScopeColumns
    {
        return new ScopeColumns(visibleToWholeOrganization: true);
    }

    /** @return BelongsTo<NotificationTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(NotificationTemplate::class);
    }

    /** Masked for display: phone or email with most characters hidden. */
    public function maskedDestination(): string
    {
        if (str_contains($this->destination, '@')) {
            [$local, $domain] = explode('@', $this->destination, 2);

            return substr($local, 0, 1).'***@'.$domain;
        }

        return str_repeat('*', max(0, strlen($this->destination) - 4)).substr($this->destination, -4);
    }
}
