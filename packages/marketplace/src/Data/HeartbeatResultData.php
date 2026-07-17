<?php

declare(strict_types=1);

namespace Capell\Marketplace\Data;

use Capell\Core\Data\Marketplace\ExtensionHealthAlertData;
use Override;
use Spatie\LaravelData\Data;

final class HeartbeatResultData extends Data
{
    /**
     * @param  array<int, UpdateNoticeData>  $updates
     * @param  array<int, AdvisoryNoticeData>  $advisories
     * @param  array<int, ExtensionHealthAlertData>  $alerts
     * @param  array<string, mixed>  $policy
     * @param  array<string, mixed>|null  $commercial
     */
    public function __construct(
        public readonly string $instanceId,
        public readonly ?string $signingSecret,
        public readonly array $updates,
        public readonly array $advisories,
        public readonly array $alerts = [],
        public readonly array $policy = [],
        public readonly ?string $checkedAt = null,
        public readonly ?string $capellVersion = null,
        public readonly ?string $responseId = null,
        public readonly ?array $commercial = null,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromApiResponse(array $payload): self
    {
        $updates = array_map(
            UpdateNoticeData::fromApiResponse(...),
            is_array($payload['updates'] ?? null) ? $payload['updates'] : [],
        );

        $advisories = array_map(
            AdvisoryNoticeData::fromApiResponse(...),
            is_array($payload['advisories'] ?? null) ? $payload['advisories'] : [],
        );

        $alerts = array_map(
            ExtensionHealthAlertData::fromApiResponse(...),
            self::listOfArrays($payload['alerts'] ?? []),
        );

        return new self(
            instanceId: (string) ($payload['instance_id'] ?? ''),
            signingSecret: isset($payload['signing_secret']) && is_string($payload['signing_secret'])
                ? $payload['signing_secret']
                : null,
            updates: $updates,
            advisories: $advisories,
            alerts: $alerts,
            policy: is_array($payload['policy'] ?? null) ? $payload['policy'] : [],
            checkedAt: isset($payload['checked_at']) ? (string) $payload['checked_at'] : null,
            capellVersion: isset($payload['capell_version']) ? (string) $payload['capell_version'] : null,
            responseId: isset($payload['response_id']) ? (string) $payload['response_id'] : null,
            commercial: is_array($payload['commercial'] ?? null) ? $payload['commercial'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return array_filter([
            'checked_at' => $this->checkedAt,
            'capell_version' => $this->capellVersion,
            'updates' => array_map(fn (UpdateNoticeData $update): array => $update->toArray(), $this->updates),
            'advisories' => array_map(fn (AdvisoryNoticeData $advisory): array => $advisory->toArray(), $this->advisories),
            'alerts' => array_map(fn (ExtensionHealthAlertData $alert): array => $alert->toArray(), $this->alerts),
            'policy' => $this->policy,
            'response_id' => $this->responseId,
            'instance_id' => $this->instanceId,
            'commercial' => $this->commercial,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function listOfArrays(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter($values, is_array(...)));
    }
}
