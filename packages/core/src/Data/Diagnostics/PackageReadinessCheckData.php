<?php

declare(strict_types=1);

namespace Capell\Core\Data\Diagnostics;

use Spatie\LaravelData\Data;

final class PackageReadinessCheckData extends Data
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly bool $passed,
        public readonly string $severity = 'info',
        public readonly string $message = '',
    ) {}

    public function blocksReadiness(): bool
    {
        return ! $this->passed && $this->severity === 'critical';
    }

    public function warnsReadiness(): bool
    {
        return ! $this->passed && $this->severity === 'warning';
    }
}
