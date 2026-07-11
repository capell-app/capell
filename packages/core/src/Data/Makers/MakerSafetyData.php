<?php

declare(strict_types=1);

namespace Capell\Core\Data\Makers;

use Illuminate\Support\Collection;
use Spatie\LaravelData\Data;

class MakerSafetyData extends Data
{
    /**
     * @param  Collection<int, string>  $allowedRoots
     * @param  Collection<int, string>  $messages
     */
    public function __construct(
        public bool $phpWritesAllowed,
        public bool $databaseWritesAllowed,
        public Collection $allowedRoots,
        public string $environment,
        public Collection $messages,
    ) {}
}
