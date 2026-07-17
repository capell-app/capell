<?php

declare(strict_types=1);

namespace Capell\Core\Data\Install;

final readonly class DeveloperToolingChoiceData
{
    public function __construct(
        public bool $installDeveloperTooling,
        public bool $configureBoostDeveloperTooling,
    ) {}
}
