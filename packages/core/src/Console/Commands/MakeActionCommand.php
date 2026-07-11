<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Actions\Makers\RunMakerAction;
use Capell\Core\Console\Commands\Concerns\DescribesCommandOptions;
use Capell\Core\Data\Makers\MakerInputData;
use Illuminate\Console\Command;
use Throwable;

class MakeActionCommand extends Command
{
    use DescribesCommandOptions;

    protected $description = 'Generate a new Capell Action class (and optional Data companion) in App\\Actions';

    protected $signature = 'capell:make-action
        {name : The name of the action, without the Action suffix (e.g. CreateBlogPost)}
        {--data : Also generate a companion Data class in App\\Data}
        {--F|force : Overwrite existing files after warning}';

    public function handle(): int
    {
        $this->writeCommandIntro('generate a Capell action', $this->enabledOptionDetails([
            'data' => 'a companion Data class',
            'force' => 'overwrite enabled',
        ]));

        try {
            $result = RunMakerAction::run(new MakerInputData(
                maker: 'core.action',
                values: ['name' => $this->argument('name'), 'data' => $this->option('data')],
                dryRun: false,
                force: $this->option('force'),
                databaseWrites: false,
            ));
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
