<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

use Spatie\LaravelData\Data;

final class RobotsDirectiveData extends Data
{
    /**
     * @param  list<string>  $allow
     * @param  list<string>  $disallow
     */
    public function __construct(
        public readonly string $userAgent,
        public readonly array $allow,
        public readonly array $disallow,
    ) {}
}
