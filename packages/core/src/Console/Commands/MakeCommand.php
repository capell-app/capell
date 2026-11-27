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
use Capell\Core\Exceptions\NonInteractiveInputRequiredException;
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
        {--list : List available generators}
        {--name= : Primary generated name}
        {--type= : Optional type/schema/component type}
        {--source= : Optional source schema/component key}
        {--livewire : Generate Livewire files when supported}
        {--database : Allow database writes when supported}
        {--dry-run : Preview without writing}
        {--F|force : Overwrite existing files after warning}';

    public function handle(MakerRegistryInterface $registry): int
    {
        if ($this->option('list')) {
            foreach ($registry->all() as $registeredMaker) {
                $definition = $registeredMaker->definition();
                $this->line(sprintf('%s: %s', $definition->key, $definition->description));
            }

            $this->line('capell:make:extension: Create an extension package');
            $this->line('capell:make:theme: Create a theme package');

            return static::SUCCESS;
        }

        $this->writeCommandIntro('run a Capell maker', $this->enabledOptionDetails([
            'name' => 'a provided name',
            'type' => 'a selected type',
            'source' => 'a selected source',
            'livewire' => 'Livewire files',
            'database' => 'database writes enabled',
            'dry-run' => 'a dry run',
            'force' => 'overwrite enabled',
        ]));

        try {
            $maker = $this->resolveRegisteredMaker($registry);

            if ($maker === null) {
                return static::FAILURE;
            }

            $values = [
                'name' => $this->resolveName(),
                'type' => $this->option('type'),
                'source' => $this->option('source'),
                'livewire' => $this->option('livewire'),
            ];
        } catch (NonInteractiveInputRequiredException $nonInteractiveInputRequiredException) {
            $this->error($nonInteractiveInputRequiredException->getMessage());

            return static::FAILURE;
        }

        if ($this->option('dry-run')) {
            try {
                $preview = PreviewMakerAction::run(
                    maker: $maker,
                    values: $values,
                    force: $this->option('force'),
                    databaseWrites: $this->option('database'),
                );
            } catch (Throwable $throwable) {
                $this->error($throwable->getMessage());

                return static::FAILURE;
            }

            $this->line($maker);
            $this->renderFiles($preview->files);
            foreach ($preview->commands as $command) {
                $this->line($command);
            }

            $this->renderNextSteps($preview->notes);

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
        $this->renderNextSteps($result->notes);

        return $result->successful ? static::SUCCESS : static::FAILURE;
    }

    /**
     * Resolve the maker key the caller named. A key nobody registered is reported with the
     * keys that do exist, so a mistyped key never ends in a stack trace; null means it was.
     *
     * @throws NonInteractiveInputRequiredException
     */
    private function resolveRegisteredMaker(MakerRegistryInterface $registry): ?string
    {
        $makerArgument = $this->argument('maker');
        $keys = $registry->all()
            ->map(fn (Maker $registeredMaker): string => $registeredMaker->definition()->key)
            ->values()
            ->all();

        if (! is_string($makerArgument) || $makerArgument === '') {
            $this->requireInteractiveOrFail('Maker', 'Pass a maker argument, or run php artisan capell:make --list to see what each one makes.', $keys);

            return (string) select(
                label: 'Which maker should run?',
                options: $keys,
            );
        }

        if (! $registry->has($makerArgument)) {
            $this->error(sprintf(
                'Maker [%s] is not registered. Valid makers: %s. Run php artisan capell:make --list to see what each one makes.',
                $makerArgument,
                implode(', ', $keys),
            ));

            return null;
        }

        return $makerArgument;
    }

    private function resolveName(): string
    {
        $name = $this->option('name');

        if (is_string($name) && $name !== '') {
            return $name;
        }

        $this->requireInteractiveOrFail('Name', 'Pass --name=<name>, for example --name=HeroBanner.');

        return text(
            label: 'Name',
            required: true,
        );
    }

    /**
     * @param  iterable<string>  $notes
     */
    private function renderNextSteps(iterable $notes): void
    {
        foreach ($notes as $note) {
            $this->line($note);
        }
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
