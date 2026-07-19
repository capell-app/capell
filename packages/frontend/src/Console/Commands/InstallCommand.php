<?php

declare(strict_types=1);

namespace Capell\Frontend\Console\Commands;

use Capell\Core\Actions\PublishMigrationsAction;
use Capell\Core\Console\Commands\Concerns\DescribesCommandOptions;
use Capell\Core\Support\Migration\MigrationFilesystemInterface;
use Capell\Frontend\Actions\GenerateTailwindAssetsAction;
use Capell\Frontend\Contracts\SettingsMigrationProviderInterface;
use Illuminate\Console\Command;

class InstallCommand extends Command
{
    use DescribesCommandOptions;

    protected $signature = 'capell:frontend-install {--dev : Run the Vite dev build instead of production build}';

    protected $description = 'Install the Capell Frontend package';

    public function __construct(private readonly MigrationFilesystemInterface $filesystem)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->writeCommandIntro('install Capell Frontend', $this->enabledOptionDetails([
            'dev' => 'development build mode',
        ]));

        $settings = __DIR__ . '/../../../database/settings';
        if (! $this->filesystem->isDir($settings)) {
            $this->error('Settings directory does not exist.');

            return Command::FAILURE;
        }

        $publishResult = PublishMigrationsAction::run(
            type: 'settings',
            items: resolve(SettingsMigrationProviderInterface::class)->getSettingMigrations(),
            path: $settings,
        );

        foreach ($publishResult->warnings as $warning) {
            $this->warn($warning);
        }

        foreach ($publishResult->errors as $error) {
            $this->error($error);
        }

        if (! $publishResult->successful()) {
            return self::FAILURE;
        }

        foreach ($publishResult->lines as $line) {
            $this->line($line);
        }

        $this->call('vendor:publish', ['--tag' => 'capell-frontend-assets', '--force' => true]);

        $this->call('vendor:publish', ['--tag' => 'capell-frontend-publish', '--force' => true]);

        foreach (GenerateTailwindAssetsAction::run() as $result) {
            $this->line(sprintf('Generated Tailwind assets at: %s', $result['path']));
        }

        $this->newLine();
        $this->info('Capell Frontend installed successfully.');

        return self::SUCCESS;
    }
}
