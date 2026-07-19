<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Models\Blueprint;
use Capell\Core\Support\BlueprintBlockSchema;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Console\Command\Command as CommandAlias;

final class ExportBlueprintBlockSchemaCommand extends Command
{
    protected $signature = 'capell:blueprint-block-schema
        {blueprint? : Blueprint key}
        {--all : Export every blueprint keyed by blueprint key}
        {--out= : Write JSON to this path instead of stdout}';

    protected $description = 'Export JSON Schema for content block payloads accepted by one or all blueprints.';

    public function handle(Filesystem $filesystem): int
    {
        $blueprintKey = $this->argument('blueprint');

        if (! is_string($blueprintKey) && ! $this->option('all')) {
            $this->components->error('Pass a blueprint key or use --all.');

            return CommandAlias::INVALID;
        }

        $schema = $this->option('all')
            ? Blueprint::query()->orderBy('key')->get()->mapWithKeys(
                static fn (Blueprint $blueprint): array => [$blueprint->key => BlueprintBlockSchema::for($blueprint)],
            )->all()
            : BlueprintBlockSchema::for(Blueprint::query()->where('key', $blueprintKey)->firstOrFail());
        $json = json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        $outputPath = $this->option('out');

        if (is_string($outputPath) && $outputPath !== '') {
            $filesystem->ensureDirectoryExists(dirname($outputPath));
            $filesystem->put($outputPath, $json);
            $this->components->info(sprintf('Blueprint block schema written to [%s].', $outputPath));

            return CommandAlias::SUCCESS;
        }

        $this->output->write($json);

        return CommandAlias::SUCCESS;
    }
}
