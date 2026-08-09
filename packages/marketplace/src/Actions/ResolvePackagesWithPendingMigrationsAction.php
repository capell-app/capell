<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Migration\MigrationFileScanner;
use Capell\Marketplace\Support\MarketplaceMigrationLedger;
use Illuminate\Support\Facades\File;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Which installed packages are still carrying migrations this site has not run.
 *
 * An update runs `PublishPendingMigrationsAction` and the two migrate actions,
 * and all three are global by construction — they take no package and Laravel's
 * migrator has no notion of which package a published migration file came from.
 * So updating extension A also applies extension B's pending migration.
 *
 * Under `capell:upgrade` that is the point: the operator asked for a whole-site
 * upgrade. Under `capell:marketplace:auto-update` at 03:20 nobody asked for
 * anything, and if A's health check then fails, A is rolled back while B is left
 * code-behind-schema with no failure record against B at all. This class is what
 * lets the unattended path decline that.
 */
final class ResolvePackagesWithPendingMigrationsAction
{
    use AsFake;
    use AsObject;

    /**
     * Composer names of installed packages with at least one migration that is
     * not recorded in the migration repository.
     *
     * An unreadable ledger reports every package with migration files as
     * pending, for the same reason `MarketplaceMigrationLedger` answers "yes" to
     * "did the schema move" when it cannot tell: refusing an unattended update
     * costs a night, running one blind costs a schema nobody knows moved.
     *
     * @return list<string>
     */
    public function handle(): array
    {
        $ledger = MarketplaceMigrationLedger::capture();
        $alreadyRan = array_fill_keys(array_map($this->withoutTimestamp(...), $ledger->applied), true);
        $pending = [];

        foreach (CapellCore::getInstalledPackages() as $package) {
            if ($package->path === null) {
                continue;
            }

            // Schema migrations only. Settings migrations are not recorded in
            // the migration repository this reads, so for them the scanner
            // cannot tell "not run" from "not visible" — and a gate that cannot
            // tell those apart would refuse every unattended update forever,
            // which is a broken gate rather than a cautious one. They also carry
            // less: a settings migration seeds rows, it does not move the schema
            // out from under restored code.
            $migrationNames = $this->migrationNames($package->path . '/database/migrations');

            if ($migrationNames === []) {
                continue;
            }

            if (! $ledger->readable) {
                $pending[$package->name] = true;

                continue;
            }

            foreach ($migrationNames as $migrationName) {
                if (! isset($alreadyRan[$this->withoutTimestamp($migrationName)])) {
                    $pending[$package->name] = true;

                    break;
                }
            }
        }

        return array_keys($pending);
    }

    /** @return list<string> */
    private function migrationNames(string $path): array
    {
        return File::isDirectory($path) ? MigrationFileScanner::names($path) : [];
    }

    /**
     * Compare on the part of the name that survives publishing.
     *
     * A package ships `create_widgets_table.php.stub` and `capell:publish-
     * migrations` writes it out with a freshly generated timestamp, so the name
     * logged in the migrations table is never the name on the package's disk.
     * Matching on the timestamp-free remainder is the same normalization
     * `RunDatabaseMigrationsAction` uses for the same reason.
     */
    private function withoutTimestamp(string $migration): string
    {
        return preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}(?:_\d{2})?_/', '', $migration) ?? $migration;
    }
}
