<?php

declare(strict_types=1);

namespace Capell\Core\Data\Reporting;

use Capell\Core\Enums\Reporting\IncidentStatus;
use Capell\Core\Models\ReportingIncident;
use Carbon\CarbonImmutable;

final readonly class ReportingIncidentData
{
    /** @param array<string, string> $deliveries */
    public function __construct(
        public string $id,
        public SignalData $signal,
        public IncidentStatus $status,
        public ?string $owner,
        public ?string $backup,
        public array $deliveries,
        public CarbonImmutable $openedAt,
        public ?CarbonImmutable $acknowledgedAt,
        public ?CarbonImmutable $escalatedAt,
        public ?CarbonImmutable $resolvedAt,
        public ?string $acknowledgedBy,
    ) {}

    public static function fromIncident(ReportingIncident $incident): self
    {
        return new self(
            id: $incident->fingerprint,
            signal: SignalData::fromArray($incident->signal),
            status: $incident->status,
            owner: $incident->owner,
            backup: $incident->backup,
            deliveries: $incident->deliveries,
            openedAt: $incident->opened_at,
            acknowledgedAt: $incident->acknowledged_at,
            escalatedAt: $incident->escalated_at,
            resolvedAt: $incident->resolved_at,
            acknowledgedBy: $incident->acknowledged_by,
        );
    }
}
