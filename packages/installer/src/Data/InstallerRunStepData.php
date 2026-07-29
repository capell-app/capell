<?php

declare(strict_types=1);

namespace Capell\Installer\Data;

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
        public readonly string $status,
        public readonly array $lines = [],
        public readonly ?string $nextStep = null,
        public readonly ?string $logPath = null,
        public readonly ?string $error = null,
        public readonly ?string $expectedStep = null,
        public readonly ?string $errorClass = null,
        public readonly ?string $remediation = null,
        public readonly ?array $preflight = null,
        public readonly int $statusCode = 200,
    ) {}
}
