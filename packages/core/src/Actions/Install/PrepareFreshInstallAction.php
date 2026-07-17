<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Actions\DeletePackageMigrationsAction;
use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\PackageData;
use Capell\Core\Events\DatabaseSchemaChanged;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Database\RuntimeSchemaState;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Event;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class PrepareFreshInstallAction
{
    use AsFake;
    use AsObject;

    public function handle(ProgressReporter $reporter): void
    {
        $reporter->step('Refreshing database for fresh Capell install…');
        $report = $this->deletePublishedMigrations();

        if ($report['deleted'] > 0 || $report['blocked'] > 0 || $report['skipped'] > 0) {
            $reporter->report(sprintf(
                'Published migrations cleaned: %d deleted, %d blocked, %d skipped.',
                $report['deleted'],
                $report['blocked'],
                $report['skipped'],
            ));
        }

        resolve(Kernel::class)->call('db:wipe', [
            '--force' => true,
        ]);

        $this->flushRuntimeSchemaState();

        $reporter->report('Database refreshed.');
    }

    /**
     * @return array{deleted: int, blocked: int, skipped: int}
     */
    private function deletePublishedMigrations(): array
    {
        return CapellCore::getPackages(withoutCore: false)
            ->map(fn (PackageData $package): array => DeletePackageMigrationsAction::run($package))
            ->reduce(
                fn (array $carry, array $packageReport): array => [
                    'deleted' => $carry['deleted'] + $packageReport['deleted'],
                    'blocked' => $carry['blocked'] + $packageReport['blocked'],
                    'skipped' => $carry['skipped'] + $packageReport['skipped'],
                ],
                ['deleted' => 0, 'blocked' => 0, 'skipped' => 0],
            );
    }

    private function flushRuntimeSchemaState(): void
    {
        if (app()->bound(RuntimeSchemaState::class)) {
            resolve(RuntimeSchemaState::class)->flush();
        }

        Event::dispatch(new DatabaseSchemaChanged);
    }
}
