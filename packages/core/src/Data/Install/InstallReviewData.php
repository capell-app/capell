<?php

declare(strict_types=1);

namespace Capell\Core\Data\Install;

use Spatie\LaravelData\Data;

final class InstallReviewData extends Data
{
    /** @param array<string, string> $items */
    public function __construct(public readonly array $items) {}
}
