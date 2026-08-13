<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Actions\BuildPackageCacheAction;
use Illuminate\Console\Command;

final class PackageCacheCommand extends Command
{
    protected $description = 'Create a cache file for faster Capell package manifest loading';

    protected $signature = 'capell:package-cache';

    public function handle(BuildPackageCacheAction $buildPackageCache): int
    {
        $buildPackageCache->handle();

        $this->components->info('Capell package manifest cache created successfully.');

        return self::SUCCESS;
    }
}
