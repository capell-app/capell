<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Actions\Makers\RunMakerAction;
use Capell\Core\Console\Commands\Concerns\DescribesCommandOptions;
use Capell\Core\Data\Makers\MakerInputData;
use Illuminate\Console\Command;
use Throwable;

class MakeDataCommand extends Command
{
    use DescribesCommandOptions;

    protected $description = 'Generate a new Spatie\\LaravelData\\Data subclass in App\\Data';

    protected $signature = 'capell:make-data
        {name : The name of the Data class, without the Data suffix (e.g. BlogPost)}
        {--F|force : Overwrite existing files after warning}';

    public function handle(): int
    {
        $this->writeCommandIntro('generate a Data class', $this->enabledOptionDetails([
            'force' => 'overwrite enabled',
        ]));

        try {
            $result = RunMakerAction::run(new MakerInputData('core.data', ['name' => $this->argument('name')], false, $this->option('force'), false));
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());

            return static::FAILURE;
        }

        foreach ($result->files as $file) {
            $this->info(sprintf('%s: %s', $file->operation, $file->path));
        }

        return static::SUCCESS;
    }
}
