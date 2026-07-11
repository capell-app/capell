<?php

declare(strict_types=1);

namespace Capell\Core\Data;

final readonly class UpgradeRunOptions
{
    /**
     * @param  list<string>  $caches
     * @param  list<string>  $forceStepIds
     */
    public function __construct(
        public bool $dryRun = false,
        public bool $force = false,
        public bool $forceDowngrade = false,
        public bool $noClearCache = false,
        public bool $skipMigrations = false,
        public bool $skipSteps = false,
        public bool $onlyMigrations = false,
        public bool $onlySteps = false,
        public array $caches = [],
        public array $forceStepIds = [],
        public bool $interactive = false,
    ) {}
}
