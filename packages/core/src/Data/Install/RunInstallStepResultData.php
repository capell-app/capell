<?php

declare(strict_types=1);

namespace Capell\Core\Data\Install;

use Spatie\LaravelData\Data;

final class RunInstallStepResultData extends Data
{
    public function __construct(
        public readonly ?int $resolvedUserId,
        public readonly bool $packageMetadataRefreshed,
    ) {}
}
