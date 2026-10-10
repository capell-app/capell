<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

use Capell\Core\Contracts\Health\HealthCheck;
use Capell\Core\Data\Health\HealthCheckResultData;
use Capell\Core\Enums\Health\HealthSeverity;
use Capell\Core\Enums\Health\HealthStatus;
use Closure;
use Override;

final readonly class HealthTestCheck implements HealthCheck
{
    /**
     * @param  non-empty-string  $checkId
     * @param  non-empty-string  $checkCategory
     * @param  positive-int  $timeout
     */
    public function __construct(private string $checkId, private string $checkCategory = 'runtime', private int $timeout = 2, private ?Closure $callback = null) {}

    #[Override]
    public function id(): string
    {
        return $this->checkId;
    }

    #[Override]
    public function category(): string
    {
        return $this->checkCategory;
    }

    #[Override]
    public function timeoutSeconds(): int
    {
        return $this->timeout;
    }

    #[Override]
    public function run(): HealthCheckResultData
    {
        return $this->callback instanceof Closure
            ? ($this->callback)()
            : new HealthCheckResultData($this->checkId, $this->checkCategory, HealthStatus::Healthy, HealthSeverity::Info, 'Healthy.');
    }
}
