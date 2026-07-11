<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Data\MigrationPublishCommandResultData;
use Capell\Core\Support\Dataset\DatasetPublisher;
use Capell\Core\Support\Migration\MigrationFilesystemInterface;
use Lorisleiva\Actions\Concerns\AsObject;

final class PublishMigrationsAction
{
    use AsObject;

    public function __construct(
        private readonly DatasetPublisher $publisher,
        private readonly MigrationFilesystemInterface $files,
    ) {}

    /**
     * @param  list<string>  $items
     */
    public function handle(string $type = 'migrations', array $items = [], ?string $path = null): MigrationPublishCommandResultData
    {
        $isPathProvided = ! in_array($path, [null, '', '0'], true);
        $normalizedPath = $isPathProvided && is_string($path) ? $this->publisher->normalizePath($path) : null;

        if ($items === []) {
            return new MigrationPublishCommandResultData(applied: 0, blocked: 0, errors: ['The --items option is required.']);
        }

        if ($isPathProvided && ! $this->files->isDir((string) $normalizedPath)) {
            return new MigrationPublishCommandResultData(applied: 0, blocked: 0, errors: ['The --path option must be a valid directory if provided.']);
        }

        if (! $this->publisher->validateType($type)) {
            return new MigrationPublishCommandResultData(applied: 0, blocked: 0, errors: ['The --type option must be either "migrations" or "settings".']);
        }

        $destinationError = $this->prepareDestinationDirectory($type);
        if ($destinationError !== null) {
            return new MigrationPublishCommandResultData(applied: 0, blocked: 0, errors: [$destinationError]);
        }

        $applied = 0;
        $blocked = 0;
        $lines = [];
        $warnings = [];

        foreach ($items as $itemPathOrName) {
            if (! $isPathProvided && ! $this->files->fileExists($itemPathOrName)) {
                return new MigrationPublishCommandResultData(
                    applied: $applied,
                    blocked: $blocked,
                    lines: $lines,
                    warnings: $warnings,
                    errors: [sprintf("File '%s' does not exist.", $itemPathOrName)],
                );
            }

            $sourceAndTarget = $this->resolveSourceAndTarget(
                $itemPathOrName,
                $isPathProvided,
                $normalizedPath,
                $warnings,
            );

            if ($sourceAndTarget === null) {
                $blocked++;

                continue;
            }

            [$source, $targetName] = $sourceAndTarget;

            $lines[] = $this->publishItem($source, $targetName, $type);
            $applied++;
        }

        return new MigrationPublishCommandResultData(
            applied: $applied,
            blocked: $blocked,
            lines: $lines,
            warnings: $warnings,
        );
    }

    /**
     * @param  list<string>  $warnings
     * @return array{string, string}|null [source, targetName] or null if not found
     */
    private function resolveSourceAndTarget(
        string $itemPathOrName,
        bool $isPathProvided,
        ?string $normalizedPath,
        array &$warnings,
    ): ?array {
        if ($isPathProvided) {
            $sourceItemName = $this->sourceItemName($itemPathOrName);
            $sourcePhp = $normalizedPath . $sourceItemName . '.php';
            $sourceStub = $normalizedPath . $sourceItemName . '.php.stub';

            if ($this->files->fileExists($sourcePhp)) {
                return [$sourcePhp, basename($sourcePhp)];
            }

            if ($this->files->fileExists($sourceStub)) {
                return [$sourceStub, basename($sourcePhp)];
            }

            $warnings[] = sprintf("Source file '%s' or '%s' does not exist. Skipping.", $sourcePhp, $sourceStub);

            return null;
        }

        $source = $itemPathOrName;
        $extension = pathinfo($source, PATHINFO_EXTENSION);

        if ($extension === 'stub' && str_ends_with($source, '.php.stub')) {
            return [$source, basename(substr($source, 0, -5))];
        }

        if ($extension === 'php') {
            return [$source, basename($source)];
        }

        $phpVariant = $source . '.php';
        $stubVariant = $source . '.php.stub';

        if ($this->files->fileExists($phpVariant)) {
            return [$phpVariant, basename($phpVariant)];
        }

        if ($this->files->fileExists($stubVariant)) {
            return [$stubVariant, basename($phpVariant)];
        }

        $warnings[] = sprintf("File '%s' must end with .php or .php.stub, or a file with those extensions must exist. Skipping.", $source);

        return null;
    }

    private function publishItem(string $source, string $targetName, string $type): string
    {
        $destinationDirectory = database_path($type);
        $destination = $destinationDirectory . DIRECTORY_SEPARATOR . $targetName;

        $start = microtime(true);
        $this->files->copy($source, $destination);
        $elapsed = (microtime(true) - $start) * 1000;

        $dots = str_repeat('.', max(1, 100 - mb_strlen($targetName)));

        return sprintf('%s %s ', $targetName, $dots) . number_format($elapsed, 2) . 'ms DONE';
    }

    private function sourceItemName(string $pathOrName): string
    {
        if (str_ends_with($pathOrName, '.php.stub')) {
            return substr($pathOrName, 0, -9);
        }

        if (str_ends_with($pathOrName, '.php')) {
            return substr($pathOrName, 0, -4);
        }

        return pathinfo($pathOrName, PATHINFO_FILENAME);
    }

    private function prepareDestinationDirectory(string $type): ?string
    {
        $destinationDirectory = database_path($type);

        if (! $this->files->isDir($destinationDirectory)) {
            $this->files->makeDir($destinationDirectory);
        }

        if ($this->files->isDir($destinationDirectory) && $this->files->isWritable($destinationDirectory)) {
            return null;
        }

        return sprintf(
            "Cannot publish %s because '%s' is not writable by the current PHP process.",
            $type,
            $destinationDirectory,
        );
    }
}
