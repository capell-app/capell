<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Data\PackageData;
use Capell\Core\Support\Migration\MigrationFilesystemInterface;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static array{deleted: int, blocked: int, skipped: int} run(PackageData $package)
 */
final class DeletePackageMigrationsAction
{
    use AsFake;
    use AsObject;

    public function __construct(private readonly MigrationFilesystemInterface $files) {}

    /**
     * @return array{deleted: int, blocked: int, skipped: int}
     */
    public function handle(PackageData $package): array
    {
        $sourceDirectory = $package->path === null ? null : $package->path . '/database/migrations';

        if ($sourceDirectory === null || ! $this->files->isDir($sourceDirectory)) {
            return [
                'deleted' => 0,
                'blocked' => 0,
                'skipped' => 0,
            ];
        }

        $report = [
            'deleted' => 0,
            'blocked' => 0,
            'skipped' => 0,
        ];

        foreach ($this->publishedMigrationPaths($sourceDirectory) as $publishedMigrationPath) {
            if (! $this->files->fileExists($publishedMigrationPath)) {
                $report['skipped']++;

                continue;
            }

            if ($this->files->delete($publishedMigrationPath)) {
                $report['deleted']++;

                continue;
            }

            $report['blocked']++;
        }

        return $report;
    }

    /**
     * @return list<string>
     */
    private function publishedMigrationPaths(string $sourceDirectory): array
    {
        $sourceMigrationPaths = array_merge(
            $this->files->glob($sourceDirectory . '/*.php'),
            $this->files->glob($sourceDirectory . '/*.php.stub'),
        );

        sort($sourceMigrationPaths);

        return array_values(collect($sourceMigrationPaths)
            ->map(fn (string $sourceMigrationPath): string => str($sourceMigrationPath)
                ->basename()
                ->replaceEnd('.php.stub', '.php')
                ->toString())
            ->unique()
            ->map(fn (string $migrationName): string => database_path('migrations/' . $migrationName))
            ->values()
            ->all());
    }
}
