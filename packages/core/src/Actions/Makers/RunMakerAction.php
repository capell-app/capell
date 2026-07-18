<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Makers;

use Capell\Core\Contracts\Makers\MakerRegistryInterface;
use Capell\Core\Data\Makers\MakerInputData;
use Capell\Core\Data\Makers\MakerResultData;
use Capell\Core\Support\Makers\MakerSafety;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

class RunMakerAction
{
    use AsFake;
    use AsObject;

    public function __construct(
        private readonly MakerRegistryInterface $registry,
        private readonly MakerSafety $safety,
    ) {}

    public function handle(MakerInputData $input): MakerResultData
    {
        $maker = $this->registry->get($input->maker);
        $definition = $maker->definition();
        $preview = $maker->preview($input);
        $warnings = collect();

        throw_if($definition->supportsPhpWrites && ! $this->safety->current()->phpWritesAllowed, RuntimeException::class, 'PHP writes are disabled.');

        throw_if($input->databaseWrites && $definition->supportsDatabaseWrites && ! $this->safety->current()->databaseWritesAllowed, RuntimeException::class, 'Database writes are disabled.');

        foreach ($preview->files as $file) {
            if (! $this->safety->pathIsAllowed($file->path)) {
                throw new RuntimeException(sprintf('Path [%s] is outside configured maker roots.', $file->path));
            }

            if ($file->exists && ! $input->force) {
                throw new RuntimeException(sprintf('File [%s] already exists. Re-run with --force to overwrite it.', $file->path));
            }
        }

        if ($input->dryRun) {
            return new MakerResultData(
                maker: $preview->maker,
                files: $preview->files,
                databaseRecords: $preview->databaseRecords,
                commands: $preview->commands,
                notes: $preview->notes,
                successful: true,
                warnings: $warnings,
            );
        }

        return $maker->run($input);
    }
}
