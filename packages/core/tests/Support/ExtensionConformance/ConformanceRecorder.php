<?php

declare(strict_types=1);

namespace Vendor\ExtensionConformance;

final class ConformanceRecorder
{
    /** @var list<string> */
    private array $events = [];

    public function record(string $bucket): void
    {
        $this->events[] = $bucket;
    }

    /** @return list<string> */
    public function events(): array
    {
        return $this->events;
    }
}
