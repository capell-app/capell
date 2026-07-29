<?php

declare(strict_types=1);

namespace Capell\Installer\Data;

use Capell\Installer\Enums\InstallerRunMode;
use Spatie\LaravelData\Data;

final class InstallerRunStartData extends Data
{
    /**
     * @param  array<int, array{key: string, label: string}>  $plan
     */
    public function __construct(
        public readonly string $installId,
        public readonly InstallerRunMode $mode,
        public readonly string $status,
        public readonly array $plan,
        public readonly ?string $nextStep,
        public readonly string $logPath,
        public readonly bool $completed,
    ) {}
}
