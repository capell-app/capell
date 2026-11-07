<?php

declare(strict_types=1);

namespace Capell\Admin\Data;

use Spatie\LaravelData\Data;

final class AdminHelpEntryData extends Data
{
    public function __construct(
        public string $title,
        public string $summary,
        public string $nextAction,
        public ?string $docsUrl = null,
    ) {}
}
