<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Actions\Themes\ActivateThemeForSitesAction;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Core\ThemeStudio\Theme\ThemeRegistry;
use Illuminate\Console\Command;

final class ActivateThemeCommand extends Command
{
    protected $description = 'Make a theme the live theme for one or more sites';

    protected $signature = 'capell:theme:activate
        {theme : The theme key (for example default)}
        {--site=* : The ID of a site to switch; repeat for more sites}
        {--all-sites : Switch every site}
        {--default : Also make it the default theme for new sites}';

    public function handle(ThemeRegistry $themes): int
    {
        $themeKey = (string) $this->argument('theme');
        $record = Theme::query()->where('key', $themeKey)->first();

        if (! $record instanceof Theme && ! $themes->has($themeKey)) {
            $this->error(sprintf('No theme has the key [%s]. Installed themes: %s.', $themeKey, $this->availableThemeKeys($themes)));
            $this->line('Install a theme package with Composer and php artisan capell:extension-install, or create one with php artisan capell:make:theme.');

            return self::FAILURE;
        }

        $siteIds = $this->resolveSiteIds();

        if ($siteIds === null) {
            return self::FAILURE;
        }

        // The guard above leaves either an installed record or a registered definition.
        $name = $record instanceof Theme ? $record->name : $themes->definition($themeKey)->name;
        $theme = ActivateThemeForSitesAction::run($themeKey, $name, $siteIds, (bool) $this->option('default'));

        $this->info(sprintf('%s is now the theme for %s.', $theme->name, $siteIds === [] ? 'no sites' : 'site ' . implode(', ', $siteIds)));

        if ((bool) $this->option('default')) {
            $this->info(sprintf('%s is the default theme for new sites.', $theme->name));
        }

        return self::SUCCESS;
    }

    /**
     * @return list<int>|null
     */
    private function resolveSiteIds(): ?array
    {
        $sites = Site::query()->orderBy('id')->get(['id', 'name']);

        if ((bool) $this->option('all-sites')) {
            return array_values($sites->map(fn (Site $site): int => (int) $site->getKey())->all());
        }

        /** @var list<string> $requested */
        $requested = (array) $this->option('site');

        if ($requested === []) {
            if ($sites->count() === 1) {
                return [(int) $sites->first()?->getKey()];
            }

            if ((bool) $this->option('default')) {
                return [];
            }

            $this->error('This application has more than one site. Pass --site=<id> for each site to switch, or --all-sites.');
            $this->listSites($sites->all());

            return null;
        }

        $invalid = array_values(array_filter($requested, fn (string $id): bool => preg_match('/^[1-9]\d*$/', $id) !== 1));

        if ($invalid !== []) {
            $this->error(sprintf('A site ID is a whole number, not [%s].', implode(', ', $invalid)));
            $this->listSites($sites->all());

            return null;
        }

        $ids = array_values(array_unique(array_map(intval(...), $requested)));
        $missing = array_values(array_diff($ids, $sites->map(fn (Site $site): int => (int) $site->getKey())->all()));

        if ($missing !== []) {
            $this->error(sprintf('No site has the ID %s.', implode(', ', $missing)));
            $this->listSites($sites->all());

            return null;
        }

        return $ids;
    }

    /**
     * @param  array<int, Site>  $sites
     */
    private function listSites(array $sites): void
    {
        foreach ($sites as $site) {
            $this->line(sprintf('  %d  %s', $site->getKey(), $site->name));
        }
    }

    private function availableThemeKeys(ThemeRegistry $themes): string
    {
        $keys = collect(array_keys($themes->definitions()))
            ->merge(Theme::query()->pluck('key'))
            ->unique()
            ->sort()
            ->values();

        return $keys->isEmpty() ? 'none' : $keys->implode(', ');
    }
}
