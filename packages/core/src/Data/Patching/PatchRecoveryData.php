<?php

declare(strict_types=1);

namespace Capell\Core\Data\Patching;

use Spatie\LaravelData\Data;

final class PatchRecoveryData extends Data
{
    /**
     * @param  list<string>  $backupPaths
     * @param  list<string>  $createdPaths
     */
    public function __construct(
        public array $backupPaths = [],
        public array $createdPaths = [],
    ) {}
}
