<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Console\Commands\Concerns\DescribesCommandOptions;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class CoreFakerCommand extends Command
{
    use DescribesCommandOptions;

    /**
     * @var string
     */
    protected $signature = 'capell:core-faker {--count=25} {--sites=} {--languages=} {--force}';

    /**
     * @var string
     */
    protected $description = 'Seed fake pages across sites and languages.';

    public function handle(): int
    {
        $count = (int) $this->option('count');
        $this->writeCommandIntro('seed fake core pages', $this->coreFakerIntroDetails($count));

        if ($count < 1) {
            $this->error('The --count option must be at least 1.');

            return Command::FAILURE;
        }

        $sites = $this->resolveSites();

        if ($sites->isEmpty()) {
            $this->warn('No sites found. Skipping.');

            return Command::SUCCESS;
        }

        $languages = $this->resolveLanguages();
        $created = 0;

        $sites->each(function (Site $site) use ($count, $languages, &$created): void {
            Page::factory()
                ->count($count)
                ->site($site)
                ->withTranslations($languages)
                ->create();

            $created += $count;
            $this->info(sprintf('Seeded %d pages in site "%s".', $count, $site->name));
        });

        $this->info(sprintf('Total fake pages created: %d', $created));

        return Command::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function coreFakerIntroDetails(int $count): array
    {
        return array_values(array_filter([
            $count !== 25 ? sprintf('%d pages per site', $count) : null,
            $this->option('sites') ? 'selected sites' : null,
            $this->option('languages') ? 'selected languages' : null,
            $this->option('force') ? 'confirmation skipped' : null,
        ]));
    }

    /**
     * @return Collection<int, Site>
     */
    private function resolveSites(): Collection
    {
        $names = $this->option('sites');

        if (is_string($names) && $names !== '') {
            $names = explode(',', $names);
        }

        if (is_array($names)) {
            return Site::query()->whereIn('name', $names)->get();
        }

        return Site::query()->get();
    }

    /**
     * @return Collection<int, Language>|null
     */
    private function resolveLanguages(): ?Collection
    {
        $codes = $this->option('languages');

        if (is_string($codes) && $codes !== '') {
            $codes = explode(',', $codes);
        }

        if (is_array($codes)) {
            return Language::query()->whereIn('code', $codes)->get();
        }

        return null;
    }
}
