<?php

declare(strict_types=1);

namespace Capell\Core\Models;

use Capell\Core\Concerns\HasCapellTable;
use Capell\Core\Enums\InboundWebhookEventStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * One verified webhook delivery, stored once per provider event id. The unique
 * (provider, event_id) row is what makes a redelivered or replayed event a
 * no-op; the payload is encrypted at rest because providers send personal data.
 *
 * Finished events are pruned after RETENTION_DAYS; the signed-timestamp window
 * already refuses a captured delivery long before that.
 *
 * @property int $id
 * @property string $provider
 * @property string $event_id
 * @property string $type
 * @property string|null $subject
 * @property CarbonImmutable $occurred_at
 * @property InboundWebhookEventStatus $status
 * @property array<array-key, mixed> $payload
 * @property string $payload_hash
 * @property string|null $handler
 * @property int $attempts
 * @property string|null $last_error
 * @property CarbonImmutable|null $processed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class InboundWebhookEvent extends Model
{
    use HasCapellTable;

    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    use MassPrunable;

    public const int RETENTION_DAYS = 30;

    protected $table = 'capell_inbound_webhook_events';

    /** @var list<string> */
    protected $fillable = [
        'provider',
        'event_id',
        'type',
        'subject',
        'occurred_at',
        'status',
        'payload',
        'payload_hash',
        'handler',
        'attempts',
        'last_error',
        'processed_at',
    ];

    /** @var list<string> */
    protected $hidden = ['payload'];

    /**
     * Whether an event about the same subject from the same provider that
     * happened later has already been processed, so this one is stale and a
     * handler should not apply it over the newer state.
     */
    public function isOlderThanStored(): bool
    {
        if ($this->subject === null) {
            return false;
        }

        return self::query()
            ->where('provider', $this->provider)
            ->where('subject', $this->subject)
            ->whereKeyNot($this->getKey())
            ->where('status', InboundWebhookEventStatus::Processed->value)
            ->where('occurred_at', '>', $this->occurred_at)
            ->exists();
    }

    /** @return Builder<self> */
    public function prunable(): Builder
    {
        return self::query()
            ->whereIn('status', [InboundWebhookEventStatus::Processed->value, InboundWebhookEventStatus::Ignored->value])
            ->where('updated_at', '<', CarbonImmutable::now()->subDays(self::RETENTION_DAYS));
    }

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return [
            'status' => InboundWebhookEventStatus::class,
            'payload' => 'encrypted:array',
            'occurred_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
            'attempts' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
