<?php

declare(strict_types=1);

namespace Capell\Core\Data\Install;

use Spatie\LaravelData\Data;

final class ArtisanCommandResultData extends Data
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $output,
        public readonly string $errorOutput = '',
    ) {}

    public function combinedOutput(): string
    {
        $output = trim($this->output);
        $errorOutput = trim($this->errorOutput);

        return $output . ($output !== '' && $errorOutput !== '' ? "\n" : '') . $errorOutput;
    }
}
