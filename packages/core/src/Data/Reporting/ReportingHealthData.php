<?php

declare(strict_types=1);

namespace Capell\Core\Data\Reporting;

final readonly class ReportingHealthData
{
    /** @param 'ok'|'degraded'|'disabled'|'unavailable' $status */
    public function __construct(
        public string $status,
        public int $unresolved = 0,
        public int $acknowledged = 0,
        public int $escalated = 0,
        public int $deliveryFailures = 0,
    ) {}

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        if (in_array($this->status, ['disabled', 'unavailable'], true)) {
            return ['status' => $this->status];
        }

        return ['status' => $this->status, 'unresolved' => $this->unresolved, 'acknowledged' => $this->acknowledged, 'escalated' => $this->escalated, 'delivery_failures' => $this->deliveryFailures];
    }
}
