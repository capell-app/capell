<?php

declare(strict_types=1);

namespace Capell\Core\Data\Security;

use Spatie\LaravelData\Data;

final class SecurityNodeRefreshResultData extends Data
{
    /** @param list<string> $failures */
    public function __construct(public readonly int $acknowledged, public readonly array $failures) {}
}
