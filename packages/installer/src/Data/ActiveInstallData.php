<?php

declare(strict_types=1);

namespace Capell\Installer\Data;

use Spatie\LaravelData\Data;

final class ActiveInstallData extends Data
{
    public function __construct(
        public readonly string $installId,
        public readonly string $status,
        public readonly string $progressUrl,
        public readonly string $reportUrl,
        public readonly bool $queued,
        public readonly int $planStepCount,
    ) {}

    public function shortInstallId(): string
    {
        return substr($this->installId, 0, 8);
    }
}
