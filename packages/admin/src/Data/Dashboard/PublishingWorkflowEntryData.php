<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Dashboard;

use Spatie\LaravelData\Data;

final class PublishingWorkflowEntryData extends Data
{
    public function __construct(
        public readonly string $label,
        public readonly string $description,
        public readonly string $url,
        public readonly string $actionLabel,
        public readonly int $count,
    ) {}
}
