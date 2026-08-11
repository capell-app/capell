<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Health;

use Capell\Core\Data\Health\HealthCheckResultData;

interface HealthCheck
{
    public const string TAG = 'capell.health-checks';

    /** @return non-empty-string */
    public function id(): string;

    /** @return non-empty-string */
    public function category(): string;

    /** @return positive-int */
    public function timeoutSeconds(): int;

    public function run(): HealthCheckResultData;
}
