<?php

declare(strict_types=1);

namespace Capell\Core\Data\Upgrade;

use Capell\Core\Enums\Upgrade\UpgradeRunResult;

final readonly class UpgradeRunResultData
{
    public function __construct(
        public UpgradeRunResult $result,
        public ?int $exitCode = null,
        public ?string $message = null,
    ) {}
}
