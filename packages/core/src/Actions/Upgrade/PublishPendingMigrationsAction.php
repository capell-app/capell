<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Upgrade;

use Capell\Core\Data\MigrationPublishResult;
use Capell\Core\Data\PackageData;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Deployment\ReleaseRootWriteGuard;
use Capell\Core\Support\Migration\MigrationFileScanner;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

class PublishPendingMigrationsAction
{
    use AsFake;
    use AsObject;

    public function __construct(private readonly ReleaseRootWriteGuard $releaseRootWriteGuard) {}

    public function handle(bool $dryRun = false): MigrationPublishResult
    {
        if ($dryRun) {
            return new MigrationPublishResult(
                schemaPublished: false,
                settingsPublished: false,
                output: '[dry-run] would publish schema and settings migrations',
            );
        }

        $this->releaseRootWriteGuard->assertWritable(
            operation: 'Publishing pending Capell migrations',
            relativePaths: ['database/migrations', 'database/settings'],
        );

        $output = '';

        $schema = $this->publish(
            type: 'migrations',
            items: CapellCore::getMigrations(),
            path: dirname(__DIR__, 3) . '/database/migrations',
        );
        $output .= Artisan::output();

        $settings = $this->publish(
            type: 'settings',
            items: CapellCore::getSettingMigrations(),
            path: dirname(__DIR__, 3) . '/database/settings',
        );
        $output .= Artisan::output();

        foreach (CapellCore::getInstalledPackages() as $package) {
            $schema = $this->publishPackageType($package, 'migrations') && $schema;
            $output .= Artisan::output();

            $settings = $this->publishPackageType($package, 'settings') && $settings;
            $output .= Artisan::output();
        }

        return new MigrationPublishResult(
            schemaPublished: $schema,
            settingsPublished: $settings,
            output: $output,
        );
    }

    /**
     * @param  array<int, string>  $items
     */
    private function publish(string $type, array $items, string $path): bool
    {
        return Artisan::call('capell:publish-migrations', [
            '--type' => $type,
            '--items' => $items,
            '--path' => $path,
        ]) === 0;
    }

    private function publishPackageType(PackageData $package, string $type): bool
    {
        if ($package->path === null) {
            return true;
        }

        if (realpath($package->path) === realpath(dirname(__DIR__, 3))) {
            return true;
        }

        $path = $package->path . '/database/' . $type;

        if (! File::isDirectory($path)) {
            return true;
        }

        $items = MigrationFileScanner::names($path);

        if ($items === []) {
            return true;
        }

        return $this->publish($type, $items, $path);
    }
}
