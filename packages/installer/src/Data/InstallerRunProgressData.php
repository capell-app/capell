<?php

declare(strict_types=1);

namespace Capell\Installer\Data;

use Spatie\LaravelData\Data;

final class InstallerRunProgressData extends Data
{
    /**
     * @param  array<int, mixed>  $lines
     */
    public function __construct(
        public readonly string $installId,
        public readonly string $status,
        public readonly array $lines,
        public readonly bool $shouldRedirectToSuccess,
    ) {}
}
