<?php

declare(strict_types=1);

namespace Capell\Core\Data\Makers;

use Spatie\LaravelData\Data;

final class MakerInputData extends Data
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function __construct(
        public string $maker,
        public array $values,
        public bool $dryRun,
        public bool $force,
        public bool $databaseWrites,
    ) {}
}
