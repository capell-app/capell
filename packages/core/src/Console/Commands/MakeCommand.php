<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Actions\Makers\PreviewMakerAction;
use Capell\Core\Actions\Makers\RunMakerAction;
use Capell\Core\Console\Commands\Concerns\DescribesCommandOptions;
use Capell\Core\Console\Commands\Concerns\PromptsWithOptionFallback;
use Capell\Core\Contracts\Makers\Maker;
use Capell\Core\Contracts\Makers\MakerRegistryInterface;
use Capell\Core\Data\Makers\MakerFileData;
use Capell\Core\Data\Makers\MakerInputData;
use Illuminate\Console\Command;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

use Throwable;

class MakeCommand extends Command
{
    use DescribesCommandOptions;
    use PromptsWithOptionFallback;

    protected $description = 'Preview and run registered Capell makers';

    protected $signature = 'capell:make
        {maker? : Registered maker key}
        {--name= : Primary generated name}
        {--type= : Optional type/schema/component type}
        {--source= : Optional source schema/component key}
        {--livewire : Generate Livewire files when supported}
        {--database : Allow database writes when supported}
        {--dry-run : Preview without writing}
        {--F|force : Overwrite existing files after warning}';

    public function handle(MakerRegistryInterface $registry): int
    {
        $this->writeCommandIntro('run a Capell maker', $this->enabledOptionDetails([
            'name' => 'a provided name',
            'type' => 'a selected type',
            'source' => 'a selected source',
            'livewire' => 'Livewire files',
            'database' => 'database writes enabled',
            'dry-run' => 'a dry run',
            'force' => 'overwrite enabled',
        ]));

        $makerArgument = $this->argument('maker');
        $maker = is_string($makerArgument) && $makerArgument !== ''
            ? $makerArgument
            : $this->resolveMaker($registry);

        $values = [
            'name' => $this->resolveName(),
            'type' => $this->option('type'),
            'source' => $this->option('source'),
            'livewire' => $this->option('livewire'),
        ];

        if ($this->option('dry-run')) {
            $preview = PreviewMakerAction::run(
                maker: $maker,
                values: $values,
                force: $this->option('force'),
                databaseWrites: $this->option('database'),
            );

            $this->line($maker);
            $this->renderFiles($preview->files);
            foreach ($preview->commands as $command) {
                $this->line($command);
            }

            return static::SUCCESS;
        }

        try {
            $result = RunMakerAction::run(new MakerInputData(
                maker: $maker,
                values: $values,
                dryRun: false,
                force: $this->option('force'),
                databaseWrites: $this->option('database'),
            ));
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());

            return static::FAILURE;
        }

        $this->renderFiles($result->files);
        foreach ($result->notes as $note) {
            $this->line($note);
        }

        return $result->successful ? static::SUCCESS : static::FAILURE;
    }

    private function resolveMaker(MakerRegistryInterface $registry): string
    {
        $this->requireInteractiveOrFail('Maker', 'Pass a maker argument.');

        return (string) select(
            label: 'Which maker should run?',
            options: $registry->all()->map(fn (Maker $registeredMaker): string => $registeredMaker->definition()->key)->all(),
        );
    }

    private function resolveName(): string
    {
        $name = $this->option('name');

        if (is_string($name) && $name !== '') {
            return $name;
        }

        $this->requireInteractiveOrFail('Name', 'Pass --name=<name>.');

        return text(
            label: 'Name',
            required: true,
        );
    }

    /**
     * @param  iterable<MakerFileData>  $files
     */
    private function renderFiles(iterable $files): void
    {
        foreach ($files as $file) {
            $this->line(sprintf('%s: %s', $file->operation, $file->path));
        }
    }
}
