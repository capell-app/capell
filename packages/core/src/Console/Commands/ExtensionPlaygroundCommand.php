<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Console\Commands\Concerns\DescribesCommandOptions;
use Capell\Core\Testing\ExtensionTestHarness;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as CommandAlias;

final class ExtensionPlaygroundCommand extends Command
{
    use DescribesCommandOptions;

    protected $signature = 'capell:extension-playground
        {extension? : Composer package name, package directory, or capell.json path}
        {--path= : Packages directory used to resolve package names}';

    protected $description = 'Boot a local Capell extension test harness and print its contract summary.';

    public function handle(): int
    {
        $this->writeCommandIntro('boot an extension playground', $this->enabledOptionDetails([
            'path' => 'a custom packages path',
        ]));

        $extension = $this->argument('extension');

        if ($extension === null || $extension === '') {
            $this->error('Missing required extension argument.');

            return CommandAlias::FAILURE;
        }

        $harness = ExtensionTestHarness::forPackageOrPath(
            $extension,
            $this->option('path'),
        );

        $results = $harness->auditResults();

        if (collect($results)->contains(static fn (array $result): bool => $result['severity'] === 'error')) {
            $this->table(
                ['Severity', 'Message'],
                array_map(
                    static fn (array $result): array => [$result['severity'], $result['message']],
                    $results,
                ),
            );

            return CommandAlias::FAILURE;
        }

        $summary = $harness->summary();

        $this->line(sprintf('package: %s', $summary['package']));
        $this->line(sprintf('manifest: %s', $summary['manifestPath']));
        $this->line(sprintf('surfaces: %s', implode(', ', $summary['surfaces'])));
        $this->line(sprintf('providers: %d', $summary['providers']));
        $this->line(sprintf('routes: %d', $summary['routes']));
        $this->line(sprintf('migrations: %s', $summary['migrations'] ? 'yes' : 'no'));
        $this->line(sprintf('settings: %d', $summary['settings']));
        $this->line(sprintf('scheduled jobs: %d', $summary['scheduledJobs']));
        $this->line(sprintf('contributions: %d', $summary['contributions']));

        if ($results === []) {
            $this->info('No extension playground validation failures found.');

            return CommandAlias::SUCCESS;
        }

        $this->table(
            ['Severity', 'Message'],
            array_map(
                static fn (array $result): array => [$result['severity'], $result['message']],
                $results,
            ),
        );

        return CommandAlias::SUCCESS;
    }
}
