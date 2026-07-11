<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Console\Commands\Concerns\DescribesCommandOptions;
use Capell\Core\Console\Commands\Concerns\HasPackageSelection;
use Capell\Core\Console\Commands\Concerns\PromptsWithOptionFallback;
use Capell\Core\Data\PackageData;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

use function Laravel\Prompts\confirm;

class FakerCommand extends Command
{
    use DescribesCommandOptions;
    use HasPackageSelection;
    use PromptsWithOptionFallback;

    /** @var string */
    protected $signature = 'capell:faker {--count=25} {--packages} {--sites=} {--languages=} {--force}';

    /** @var string */
    protected $description = 'Seed fake data across installed packages.';

    public function handle(): int
    {
        $count = $this->resolveCount();
        $this->writeCommandIntro('seed fake package data', $this->fakerIntroDetails($count));

        if (! $this->option('force')
            && $this->input->isInteractive()
            && ! confirm('Seed fake data into every installed package?', false)
        ) {
            $this->info('Faker cancelled.');

            return Command::FAILURE;
        }

        if (! $this->option('force') && ! $this->input->isInteractive()) {
            $this->error('Seeding fake data requires --force in non-interactive mode.');

            return Command::FAILURE;
        }

        if ($count < 1) {
            $this->error('The --count option must be at least 1.');

            return Command::FAILURE;
        }

        $packages = $this->getSelectedPackages();

        if ($packages->isEmpty()) {
            $this->warn('No packages selected. Exiting command.');

            return Command::FAILURE;
        }

        $sites = $this->optionList('sites');
        $languages = $this->optionList('languages');

        $this->comment(str_repeat('-', 40));
        $this->comment(sprintf('Seeding %d fake records per package', $count));
        $this->newLine();

        $this->runFakerPackages($packages, $count, $sites, $languages);

        $this->newLine();
        $this->info('Finished seeding fake data.');

        return Command::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function fakerIntroDetails(int $count): array
    {
        return array_values(array_filter([
            $count !== 25 ? sprintf('%d records per package', $count) : null,
            $this->option('packages') ? 'interactive package selection' : null,
            $this->option('sites') ? 'selected sites' : null,
            $this->option('languages') ? 'selected languages' : null,
            $this->option('force') ? 'confirmation skipped' : null,
        ]));
    }

    private function resolveCount(): int
    {
        $count = $this->option('count');

        if (is_numeric($count)) {
            return (int) $count;
        }

        return 0;
    }

    /**
     * @return list<string>|null
     */
    private function optionList(string $name): ?array
    {
        $value = $this->option($name);

        if (is_string($value) && $value !== '') {
            return explode(',', $value);
        }

        if (is_array($value)) {
            return array_values(array_map(
                static fn (mixed $item): string => (string) $item,
                $value,
            ));
        }

        return null;
    }

    /**
     * @param  Collection<string, PackageData>  $packages
     * @param  list<string>|null  $sites
     * @param  list<string>|null  $languages
     */
    private function runFakerPackages(Collection $packages, int $count, ?array $sites, ?array $languages): void
    {
        $packages->each(function (PackageData $package) use ($count, $sites, $languages): void {
            $command = $package->getFakerCommand();

            if (in_array($command, [null, '', '0'], true)) {
                return;
            }

            $this->comment(sprintf('Running command: %s', $command));

            $params = ['--count' => $count, '--force' => true];
            $fakerParams = $package->getFakerParams();

            if (in_array('sites', $fakerParams, true) && is_array($sites) && $sites !== []) {
                $params['--sites'] = $sites;
            }

            if (in_array('languages', $fakerParams, true) && is_array($languages) && $languages !== []) {
                $params['--languages'] = $languages;
            }

            $this->call($command, $params);

            $this->comment(sprintf('Finished seeding: %s', $package->name));
            $this->newLine();
        });
    }
}
