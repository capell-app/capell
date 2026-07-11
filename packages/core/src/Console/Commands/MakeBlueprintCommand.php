<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Actions\Makers\RunMakerAction;
use Capell\Core\Console\Commands\Concerns\DescribesCommandOptions;
use Capell\Core\Data\Makers\MakerInputData;
use Illuminate\Console\Command;
use Throwable;

class MakeBlueprintCommand extends Command
{
    use DescribesCommandOptions;

    protected $description = 'Generate a new Capell page blueprint class in App\\Blueprints';

    protected $signature = 'capell:make-blueprint
        {name : The name of the blueprint (e.g. BlogPost)}
        {--F|force : Overwrite existing files after warning}';

    protected $aliases = ['capell:make-type'];

    public function handle(): int
    {
        $this->writeCommandIntro('generate a Capell page blueprint', $this->enabledOptionDetails([
            'force' => 'overwrite enabled',
        ]));

        try {
            $result = RunMakerAction::run(new MakerInputData('core.blueprint', ['name' => $this->argument('name')], false, $this->option('force'), false));
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
