<?php

declare(strict_types=1);

namespace Capell\Admin\Console\Commands;

use Capell\Admin\Contracts\Cache\AdminCacheCleaner;
use Capell\Core\Facades\CapellCore;
use Capell\Core\ThemeStudio\Discovery\LocalAppThemeDefinitionRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\View;

class ClearCacheCommand extends Command
{
    protected $signature = 'capell:admin-clear-cache';

    public function handle(): int
    {
        CapellCore::flushCache();

        View::flushFinderCache();

        resolve(LocalAppThemeDefinitionRepository::class)->clearCache();

        foreach (app()->tagged(AdminCacheCleaner::TAG) as $cacheCleaner) {
            if ($cacheCleaner instanceof AdminCacheCleaner) {
                $cacheCleaner->clear();
            }
        }

        return static::SUCCESS;
    }
}
