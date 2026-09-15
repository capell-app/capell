<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\ThemeStudio\Discovery\LocalAppThemeDefinitionRepository;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

/**
 * A production request boots with `PackageRegistryBootstrapper::canDiscoverOnDemand()`
 * false, so a cleared-but-not-yet-rebuilt cache is a hard 500 on every route until
 * someone reruns `capell:package-cache` by hand — confirmed live on 2026-09-15 when
 * this command was run standalone against web2 and the site went down for ~6 minutes.
 * Rebuild immediately by default so "clear" can never leave that window open; pass
 * `--only-clear` for the rare case a caller genuinely wants the old bare-clear
 * behaviour (e.g. a deploy step that rebuilds separately later in the same pipeline).
 */
final class PackageClearCacheCommand extends Command
{
    protected $description = 'Remove generated Capell package cache files, then rebuild them immediately unless --only-clear is passed';

    protected $signature = 'capell:package-cache:clear {--only-clear : Leave the cache cleared instead of rebuilding it immediately}';

    public function handle(Filesystem $files): int
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

        $runtimeRoleCachePath = $this->laravel->bootstrapPath('cache/capell-runtime');

        if ($files->isDirectory($runtimeRoleCachePath)) {
            $files->deleteDirectory($runtimeRoleCachePath);
            $cleared = true;
        }

        if (! $cleared) {
            $this->components->warn('No Capell package cache files found.');

            return self::SUCCESS;
        }

        $this->components->info('Capell package cache files cleared.');

        if ($this->option('only-clear')) {
            return self::SUCCESS;
        }

        return $this->call('capell:package-cache');
    }
}
