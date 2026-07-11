<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\ThemeStudio\Discovery\LocalAppThemeDefinitionRepository;
use Illuminate\Console\Command;

final class PackageClearCacheCommand extends Command
{
    protected $description = 'Remove generated Capell package cache files';

    protected $signature = 'capell:package-cache:clear';

    public function handle(): int
    {
        $cachePaths = [
            $this->laravel->bootstrapPath('cache/capell-package-manifests.php'),
            $this->laravel->bootstrapPath('cache/capell-theme-chain.php'),
            resolve(LocalAppThemeDefinitionRepository::class)->cachePath(),
        ];

        $cleared = false;

        foreach ($cachePaths as $cachePath) {
            if (! file_exists($cachePath)) {
                continue;
            }

            unlink($cachePath);
            $cleared = true;
        }

        if ($cleared) {
            $this->components->info('Capell package cache files cleared.');

            return self::SUCCESS;
        }

        $this->components->warn('No Capell package cache files found.');

        return self::SUCCESS;
    }
}
