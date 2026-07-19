<?php

declare(strict_types=1);

namespace Capell\Core\Data\ProjectBuild;

use Spatie\LaravelData\Data;

final class ProjectBuildSignatureData extends Data
{
    public function __construct(
        public readonly string $algorithm,
        public readonly string $keyId,
        public readonly string $value,
    ) {}
}
