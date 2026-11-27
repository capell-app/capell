<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Actions\Makers\RunMakerAction;
use Capell\Core\Data\Makers\MakerDatabaseRecordData;
use Capell\Core\Data\Makers\MakerInputData;
use Illuminate\Console\Command;
use Throwable;

final class MakePageTypeCommand extends Command
{
    protected $description = 'Create a Capell page type in the database';

    protected $signature = 'capell:make:page-type
        {key : The page type key in lowercase with dashes (for example service-page)}
        {--name= : The name editors see; defaults to the key in title case}
        {--dry-run : Show what would be created without writing}';

    public function handle(): int
    {
        try {
            $result = RunMakerAction::run(new MakerInputData(
                'core.page-type',
                ['key' => (string) $this->argument('key'), 'name' => (string) $this->option('name')],
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
