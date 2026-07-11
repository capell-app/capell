<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Actions\DeletePackageMigrationsAction;
use Capell\Core\Console\Commands\Concerns\DescribesCommandOptions;
use Capell\Core\Data\PackageData;
use Capell\Core\Facades\CapellCore;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as CommandAlias;

final class DeleteMigrationsCommand extends Command
{
    use DescribesCommandOptions;

    protected $signature = 'capell:delete-migrations
        {extension? : Extension package name}
        {--all : Delete published migration files for all registered Capell packages}
    ';

    protected $description = 'Delete published Capell migration files.';

    public function handle(): int
    {
        $extension = $this->argument('extension');
        $this->writeCommandIntro('delete published Capell migrations', $this->enabledOptionDetails([
            'all' => 'all registered packages',
        ]));

        if ($this->option('all')) {
            return $this->deleteAll();
        }

        if (! is_string($extension) || trim($extension) === '') {
            $this->error('Pass an extension package name or use --all.');

            return CommandAlias::FAILURE;
        }

        $extension = trim($extension);

        if (! CapellCore::getPackages(withoutCore: false)->has($extension)) {
            $this->error(sprintf("Extension '%s' is unknown.", $extension));

            return CommandAlias::FAILURE;
        }

        $package = CapellCore::getPackage($extension);

        $report = DeletePackageMigrationsAction::run($package);

        $this->line(sprintf(
            'Delete report: %d deleted, %d blocked, %d skipped.',
            $report['deleted'],
            $report['blocked'],
            $report['skipped'],
        ));

        return $report['blocked'] === 0 ? CommandAlias::SUCCESS : CommandAlias::FAILURE;
    }

    private function deleteAll(): int
    {
        $report = CapellCore::getPackages(withoutCore: false)
            ->map(fn (PackageData $package): array => DeletePackageMigrationsAction::run($package))
            ->reduce(
                fn (array $carry, array $packageReport): array => [
                    'deleted' => $carry['deleted'] + $packageReport['deleted'],
                    'blocked' => $carry['blocked'] + $packageReport['blocked'],
                    'skipped' => $carry['skipped'] + $packageReport['skipped'],
                ],
                ['deleted' => 0, 'blocked' => 0, 'skipped' => 0],
            );

        $this->line(sprintf(
            'Delete report: %d deleted, %d blocked, %d skipped.',
            $report['deleted'],
            $report['blocked'],
            $report['skipped'],
        ));

        return $report['blocked'] === 0 ? CommandAlias::SUCCESS : CommandAlias::FAILURE;
    }
}
