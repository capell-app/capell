<?php

declare(strict_types=1);

namespace Capell\Admin\Console\Commands;

use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Plugin\CapellAdminPlugin;
use Capell\Admin\Support\AdminRuntimeActivator;
use Filament\Events\ServingFilament;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Console\Command;

class CacheConfiguratorsCommand extends Command
{
    protected $description = 'Cache all configurators';

    protected $signature = 'capell:admin-cache-configurators';

    public function handle(): int
    {
        $panel = $this->resolveCapellPanel();

        if (! $panel instanceof Panel) {
            $this->error('Unable to cache configurators: no Filament panel is integrated with Capell Admin.');

            return self::FAILURE;
        }

        resolve(AdminRuntimeActivator::class)->activate();

        // Mirror the panel lifecycle so serving listeners contribute before caching.
        Filament::setCurrentPanel($panel);
        Filament::bootCurrentPanel();
        event(new ServingFilament);

        $this->info('Caching registered configurators...');

        CapellAdmin::cacheConfigurators();

        $this->info('All done!');

        return static::SUCCESS;
    }

    private function resolveCapellPanel(): ?Panel
    {
        $panels = Filament::getPanels();
        ksort($panels);

        foreach ($panels as $panel) {
            if ($panel->hasPlugin(CapellAdminPlugin::ID)) {
                return $panel;
            }
        }

        return null;
    }
}
