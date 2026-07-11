<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Diagnostics;

use Spatie\LaravelData\Data;

final class DiagnosticCheckData extends Data
{
    public function __construct(
        public readonly string $status,
        public readonly string $label,
        public readonly string $detail,
        public readonly ?string $remediation = null,
        public readonly ?string $path = null,
        public readonly ?string $generatedAt = null,
    ) {}

    public function isGreen(): bool
    {
        return $this->status === 'green';
    }

    public function isRed(): bool
    {
        return $this->status === 'red';
    }
}
