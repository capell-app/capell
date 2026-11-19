<?php

declare(strict_types=1);

namespace Capell\Core\Support\HostMonitor;

/** The three opcache figures the settings panel compares with its limits. */
final readonly class OpcacheStatus
{
    public function __construct(
        public ?int $usedMemory,
        public ?int $internedUsed,
        public ?int $cachedScripts,
    ) {}
}
