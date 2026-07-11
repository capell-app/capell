<?php

declare(strict_types=1);

namespace Capell\Core\Data\Upgrade;

final readonly class UpgradeReadinessCheckData
{
    public function __construct(
        public string $key,
        public bool $passed,
        public string $message,
        public bool $blocking = false,
    ) {}
}
