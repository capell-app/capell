<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Actions\PublishMigrationsAction;
use Capell\Core\Console\Commands\Concerns\DescribesCommandOptions;
use Illuminate\Console\Command;

class PublishMigrationsCommand extends Command
{
    use DescribesCommandOptions;

    protected $signature = 'capell:publish-migrations {--type=migrations} {--items=*} {--path=}';

    protected $description = 'Publish migrations files (migrations, settings, etc.)';

    public function handle(): int
    {
        $type = $this->option('type');
        $items = $this->option('items');
        $path = $this->option('path');

        /** @var list<string> $items */
        $items = is_array($items) ? array_values(array_filter($items, is_string(...))) : [];
        $type = is_string($type) ? $type : 'migrations';
        $path = is_string($path) ? $path : null;
        $this->writeCommandIntro('publish Capell migration files', $this->publishMigrationsIntroDetails($type, $items, $path));

        $result = PublishMigrationsAction::run($type, $items, $path);

        foreach ($result->warnings as $warning) {
            $this->warn($warning);
        }

        foreach ($result->errors as $error) {
            $this->error($error);
        }

        if (! $result->successful()) {
            return Command::FAILURE;
        }

        foreach ($result->lines as $line) {
            $this->line($line);
        }

        $this->newLine();
        $this->line(sprintf(
            'Publish report: %d applied, %d blocked.',
            $result->applied,
            $result->blocked,
        ));
        $this->info(ucfirst($type) . ' published successfully.');

        return Command::SUCCESS;
    }

    /**
     * @param  array<int, string>  $items
     * @return array<int, string>
     */
    private function publishMigrationsIntroDetails(mixed $type, array $items, mixed $path): array
    {
        return array_values(array_filter([
            is_string($type) && $type !== 'migrations' ? sprintf('%s files', $type) : null,
            $items !== [] ? sprintf('%d selected item%s', count($items), count($items) === 1 ? '' : 's') : null,
            is_string($path) && $path !== '' ? 'a custom source path' : null,
        ]));
    }
}
