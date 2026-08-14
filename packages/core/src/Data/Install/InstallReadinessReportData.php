<?php

declare(strict_types=1);

namespace Capell\Core\Data\Install;

use Capell\Core\Enums\Install\InstallReadinessStage;
use Override;
use Spatie\LaravelData\Data;

final class InstallReadinessReportData extends Data
{
    /**
     * @param  list<InstallReadinessCheckData>  $checks
     */
    public function __construct(
        public readonly InstallReadinessStage $stage,
        public readonly array $checks,
        public readonly string $schemaVersion = '1.0',
    ) {}

    public function hasBlockingFailures(): bool
    {
        return array_any($this->checks, static fn (InstallReadinessCheckData $check): bool => $check->blocking && $check->failed());
    }

    /** @return list<InstallReadinessCheckData> */
    public function blockingChecks(): array
    {
        return array_values(array_filter(
            $this->checks,
            static fn (InstallReadinessCheckData $check): bool => $check->blocking && $check->failed(),
        ));
    }

    /** @return array<string, mixed> */
    #[Override]
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'stage' => $this->stage->value,
            'ready' => ! $this->hasBlockingFailures(),
            'checks' => array_map(
                static fn (InstallReadinessCheckData $check): array => $check->toArray(),
                $this->checks,
            ),
        ];
    }
}
