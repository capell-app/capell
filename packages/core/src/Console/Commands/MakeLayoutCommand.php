<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Actions\Makers\RunMakerAction;
use Capell\Core\Data\Makers\MakerDatabaseRecordData;
use Capell\Core\Data\Makers\MakerInputData;
use Illuminate\Console\Command;
use Throwable;

final class MakeLayoutCommand extends Command
{
    protected $description = 'Create a Capell layout in the database';

    protected $signature = 'capell:make:layout
        {key : The layout key in lowercase with dashes (for example two-column)}
        {--name= : The name editors see; defaults to the key in title case}
        {--from= : Copy the containers of the layout with this key}
        {--dry-run : Show what would be created without writing}';

    public function handle(): int
    {
        try {
            $result = RunMakerAction::run(new MakerInputData(
                'core.layout',
                ['key' => (string) $this->argument('key'), 'name' => (string) $this->option('name'), 'from' => (string) $this->option('from')],
                (bool) $this->option('dry-run'),
                false,
                true,
            ));
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }

        foreach ($result->databaseRecords as $record) {
            if ($record instanceof MakerDatabaseRecordData) {
                $this->info(sprintf('%s %s: %s', $record->operation, class_basename($record->model), json_encode($record->attributes, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)));
            }
        }

        if ((bool) $this->option('dry-run')) {
            $this->line('Dry run: nothing was written.');

            return self::SUCCESS;
        }

        foreach ($result->notes as $note) {
            $this->line($note);
        }

        return self::SUCCESS;
    }
}
