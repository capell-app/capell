<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Integration\Actions;

use Capell\Core\Contracts\Health\HealthCheck;
use Capell\Core\Data\Health\HealthCheckResultData;
use Capell\Core\Enums\Health\HealthSeverity;
use Capell\Core\Enums\Health\HealthStatus;
use Override;

final class ParityContributor implements HealthCheck, ParityContribution
{
    #[Override]
    public function id(): string
    {
        return 'runtime.parity';
    }

    #[Override]
    public function category(): string
    {
        return 'runtime';
    }

    #[Override]
    public function timeoutSeconds(): int
    {
        return 5;
    }

    #[Override]
    public function run(): HealthCheckResultData
    {
        return new HealthCheckResultData(
            id: 'runtime.parity',
            category: 'runtime',
            status: HealthStatus::Healthy,
            severity: HealthSeverity::Info,
            summary: 'Runtime fixture',
        );
    }
}
