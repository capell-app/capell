<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Actions\Makers\RunMakerAction;
use Capell\Core\Console\Commands\Concerns\DescribesCommandOptions;
use Capell\Core\Data\Makers\MakerInputData;
use Illuminate\Console\Command;
use Throwable;

class MakeExtenderCommand extends Command
{
    use DescribesCommandOptions;

    protected $description = 'Generate a new PageSchemaExtender implementation in App\\Extenders';

    protected $signature = 'capell:make-extender
        {name : The name of the extender (e.g. HeroFields)}
        {hook=AfterTitle : The hook position (BeforeTitle, AfterTitle, AfterContentEditor, AfterExtraContent, BeforeSearchMeta, AfterSearchMeta)}
        {--F|force : Overwrite existing files after warning}';

    public function handle(): int
    {
        $this->writeCommandIntro('generate a page schema extender', $this->enabledOptionDetails([
            'force' => 'overwrite enabled',
        ]));

        try {
            $result = RunMakerAction::run(new MakerInputData(
                maker: 'core.extender',
                values: ['name' => $this->argument('name'), 'hook' => $this->argument('hook')],
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
