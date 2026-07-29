<?php

declare(strict_types=1);

namespace Capell\Installer\Data;

use Capell\Installer\Enums\InstallerRunStepResultCode;
use Spatie\LaravelData\Data;

final class InstallerRunStepData extends Data
{
    /**
     * @param  array<int, mixed>  $lines
     * @param  array<string, mixed>|null  $preflight
     */
    public function __construct(
        public readonly string $installId,
        public readonly string $currentStep,
        public readonly InstallerRunStepResultCode $code,
        public readonly array $lines = [],
        public readonly ?string $nextStep = null,
        public readonly ?string $logPath = null,
        public readonly ?string $expectedStep = null,
        public readonly ?string $exceptionClass = null,
        public readonly ?string $exceptionMessage = null,
        public readonly ?string $remediation = null,
        public readonly ?array $preflight = null,
    ) {}
}
