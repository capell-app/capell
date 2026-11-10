<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Dashboard;

use Spatie\LaravelData\Data;

final class FirstRunTaskData extends Data
{
    public function __construct(
        public readonly string $key,
        public readonly string $title,
        public readonly string $consequence,
        public readonly string $actionLabel,
        public readonly bool $complete,
        public readonly ?string $url,
    ) {}
}
