<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Actions\Makers\RunMakerAction;
use Capell\Core\Console\Commands\Concerns\DescribesCommandOptions;
use Capell\Core\Data\Makers\MakerInputData;
use Illuminate\Console\Command;
use Throwable;

class MakeSchemaCommand extends Command
{
    use DescribesCommandOptions;

    protected $description = 'Generate a new Capell schema class in App\\Schemas';

    protected $signature = 'capell:make-schema
        {name : The name of the schema (e.g. BlogPost)}
        {--F|force : Overwrite existing files after warning}';

    public function handle(): int
    {
        $this->writeCommandIntro('generate a Capell schema', $this->enabledOptionDetails([
            'force' => 'overwrite enabled',
        ]));

        try {
            $result = RunMakerAction::run(new MakerInputData('core.schema', ['name' => $this->argument('name')], false, $this->option('force'), false));
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());

            return static::FAILURE;
        }

        foreach ($result->files as $file) {
            $this->info(sprintf('%s: %s', $file->operation, $file->path));
        }

        foreach ($result->notes as $note) {
            $this->line($note);
        }

        return static::SUCCESS;
    }
}
