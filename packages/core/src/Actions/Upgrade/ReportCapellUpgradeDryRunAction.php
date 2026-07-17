<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Upgrade;

use Capell\Core\Contracts\UpgradeReporter;
use Capell\Core\Data\PackageData;
use Capell\Core\Data\VersionAudit;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Extensions\CapellExtensionApi;
use Composer\InstalledVersions;
use Composer\Semver\Semver;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use ReflectionObject;
use Throwable;

final class ReportCapellUpgradeDryRunAction
{
    use AsFake;
    use AsObject;

    private const string CURRENT_CAPELL_API_VERSION = CapellExtensionApi::CURRENT_VERSION;

    private const int MAX_MANIFEST_FINDINGS = 20;

    public function handle(UpgradeReporter $reporter): int
    {
        $composerVersions = ResolveInstalledComposerVersionsAction::run();
        $versionAudit = $this->versionAudit($composerVersions);

        $reporter->warn('=== DRY RUN REPORT — no changes will be made ===');
        $this->reportInstalledVersions($composerVersions, $reporter);
        $this->reportPendingSchemaMigrations('Pending core schema migrations', $this->coreMigrationNames(), $reporter);
        $this->reportUnknownSettingsMigrations('Pending core settings migrations', $reporter);
        $this->reportPackageMigrations($reporter);
        $this->reportRegisteredStepBindings($reporter);
        $this->reportManifestAudit($composerVersions, $versionAudit, $reporter);
        $reporter->error('Backup prerequisite: unknown — no verified backup health signal is available to Capell Core.');
        $reporter->error('Migration irreversibility: unknown — migrations do not declare reversibility metadata.');

        return Command::FAILURE;
    }

    /** @param array<string, string> $composerVersions */
    private function reportInstalledVersions(array $composerVersions, UpgradeReporter $reporter): void
    {
        $reporter->line('<fg=blue;options=bold>Installed Composer versions</>');

        foreach ($composerVersions as $package => $version) {
            $reporter->line(sprintf('  %s => %s', $package, $version));
        }

        if ($composerVersions === []) {
            $reporter->line('  No installed Capell Composer packages were resolved.');
        }

        $reporter->newLine();
    }

    /** @param list<string> $migrations */
    private function reportPendingSchemaMigrations(string $heading, array $migrations, UpgradeReporter $reporter): void
    {
        $reporter->line(sprintf('<fg=blue;options=bold>%s</>', $heading));

        try {
            /** @var Migrator $migrator */
            $migrator = resolve('migrator');
            $repository = $migrator->getRepository();

            if (! $repository->repositoryExists()) {
                $reporter->error('  Migration repository is unavailable; pending migrations are unknown.');
                $reporter->newLine();

                return;
            }

            $ran = array_fill_keys($repository->getRan(), true);
            $pending = array_values(array_filter($migrations, static fn (string $migration): bool => ! isset($ran[$migration])));
        } catch (Throwable) {
            $reporter->error('  Pending migrations are unknown because the migration repository could not be read.');
            $reporter->newLine();

            return;
        }

        if ($pending === []) {
            $reporter->line('  None.');
        }

        foreach ($pending as $migration) {
            $reporter->line('  ' . $migration);
        }

        $reporter->newLine();
    }

    private function reportUnknownSettingsMigrations(string $heading, UpgradeReporter $reporter): void
    {
        $reporter->line(sprintf('<fg=blue;options=bold>%s</>', $heading));
        $reporter->error('  Unknown — settings migrations use a separate repository that has no read-only status contract.');
        $reporter->newLine();
    }

    private function reportPackageMigrations(UpgradeReporter $reporter): void
    {
        $reporter->line('<fg=blue;options=bold>Installed package migrations</>');
        $packages = CapellCore::getInstalledPackages();

        if ($packages->isEmpty()) {
            $reporter->line('  None.');
            $reporter->newLine();

            return;
        }

        foreach ($packages as $package) {
            $schemaMigrations = $this->packageMigrationNames($package, 'migrations');
            $settingsMigrations = $this->packageMigrationNames($package, 'settings');
            $schemaStatus = $schemaMigrations === [] ? 'none discovered' : 'unknown pending status';
            $settingsStatus = $settingsMigrations === [] ? 'none discovered' : 'unknown pending status';

            $reporter->line(sprintf('  %s — schema: %s; settings: %s', $package->name, $schemaStatus, $settingsStatus));
        }

        $reporter->newLine();
    }

    private function reportRegisteredStepBindings(UpgradeReporter $reporter): void
    {
        $reporter->line('<fg=blue;options=bold>Pending upgrade steps</>');

        $bindings = $this->registeredUpgradeStepBindings();

        if ($bindings === []) {
            $reporter->line('  No declarative upgrade step bindings are registered.');
        }

        foreach ($bindings as $binding) {
            $reporter->line('  ' . $binding . ' — id, label, and pending status unknown without resolving extension code.');
        }

        $reporter->newLine();
    }

    /** @param array<string, string> $composerVersions */
    private function reportManifestAudit(array $composerVersions, ?VersionAudit $versionAudit, UpgradeReporter $reporter): void
    {
        $reporter->line('<fg=blue;options=bold>Manifest audit</>');

        $findings = $this->manifestFindings();

        if ($findings === []) {
            $reporter->line('  JSON declarations are structurally compatible with the Capell API declaration contract.');
        }

        foreach (array_slice($findings, 0, self::MAX_MANIFEST_FINDINGS) as $finding) {
            $reporter->error('  ' . $finding);
        }

        if (count($findings) > self::MAX_MANIFEST_FINDINGS) {
            $reporter->error(sprintf('  %d additional declaration finding(s) omitted.', count($findings) - self::MAX_MANIFEST_FINDINGS));
        }

        $this->reportVersionAudit($composerVersions, $versionAudit, $reporter);
        $reporter->newLine();
    }

    /** @param array<string, string> $composerVersions */
    private function reportVersionAudit(array $composerVersions, ?VersionAudit $versionAudit, UpgradeReporter $reporter): void
    {
        if (! $versionAudit instanceof VersionAudit) {
            $reporter->error('  Version-ledger compatibility is unknown because upgrade history could not be read.');

            return;
        }

        if (! $versionAudit->hasIssues()) {
            $reporter->line('  Composer versions match the upgrade ledger.');

            return;
        }

        foreach ($versionAudit->composerOnly as $package) {
            $reporter->error(sprintf('  %s => %s — Composer package has no ledger entry.', $package, $composerVersions[$package] ?? 'unknown'));
        }

        foreach ($versionAudit->ledgerOnly as $package) {
            $reporter->error(sprintf('  %s — ledger package is absent from Composer.', $package));
        }

        foreach ($versionAudit->downgrades as $package => $range) {
            $reporter->error(sprintf('  %s => %s — downgrade from %s.', $package, $range['to'], $range['from']));
        }
    }

    /** @return list<string> */
    private function manifestFindings(): array
    {
        $findings = [];

        foreach ($this->manifestDirectories() as $directory) {
            $manifest = $this->readJsonFile($directory . '/capell.json');
            $composer = $this->readJsonFile($directory . '/composer.json');
            $package = is_string($manifest['name'] ?? null) ? $manifest['name'] : (is_string($composer['name'] ?? null) ? $composer['name'] : basename($directory));

            if ($manifest === null) {
                $findings[] = $package . ': capell.json is unreadable JSON.';

                continue;
            }

            if (($manifest['manifest-version'] ?? null) !== 3) {
                $findings[] = $package . ': declare manifest-version 3.';
            }

            if (! is_string($manifest['capellApiVersion'] ?? null) || trim($manifest['capellApiVersion']) === '') {
                $findings[] = $package . ': declare a non-empty capellApiVersion.';
            } elseif (! $this->supportsCurrentApi($manifest['capellApiVersion'])) {
                $findings[] = $package . ': capellApiVersion does not include ' . self::CURRENT_CAPELL_API_VERSION . '.';
            }

            if (is_string($composer['name'] ?? null) && $composer['name'] !== '' && $composer['name'] !== ($manifest['name'] ?? null)) {
                $findings[] = $package . ': composer.json name must match capell.json name.';
            }
        }

        return $findings;
    }

    private function supportsCurrentApi(string $constraint): bool
    {
        try {
            return Semver::satisfies(self::CURRENT_CAPELL_API_VERSION, $constraint);
        } catch (Throwable) {
            return false;
        }
    }

    /** @return list<string> */
    private function manifestDirectories(): array
    {
        $directories = [dirname(__DIR__, 3)];

        foreach (InstalledVersions::getInstalledPackages() as $packageName) {
            if (! str_starts_with($packageName, 'capell-app/')) {
                continue;
            }

            try {
                $installPath = InstalledVersions::getInstallPath($packageName);
            } catch (Throwable) {
                continue;
            }

            if (is_string($installPath) && $installPath !== '') {
                $directories[] = $installPath;
            }
        }

        return array_values(array_unique($directories));
    }

    /** @return array<string, mixed>|null */
    private function readJsonFile(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        try {
            $data = json_decode((string) file_get_contents($path), associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    /** @return list<string> */
    private function registeredUpgradeStepBindings(): array
    {
        try {
            $reflection = new ReflectionObject(app());
            $property = $reflection->getProperty('tags');
            $tags = $property->getValue(app());
        } catch (Throwable) {
            return [];
        }

        if (! is_array($tags) || ! is_array($tags['capell.upgrade-steps'] ?? null)) {
            return [];
        }

        return array_values(array_filter($tags['capell.upgrade-steps'], is_string(...)));
    }

    /** @return list<string> */
    private function coreMigrationNames(): array
    {
        return $this->migrationNames(dirname(__DIR__, 3) . '/database/migrations', includeStubs: false);
    }

    /** @return list<string> */
    private function packageMigrationNames(PackageData $package, string $type): array
    {
        if ($package->path === null) {
            return [];
        }

        return $this->migrationNames($package->path . '/database/' . $type, includeStubs: true);
    }

    /** @return list<string> */
    private function migrationNames(string $path, bool $includeStubs): array
    {
        $migrationPaths = glob($path . '/*.php') ?: [];

        if ($includeStubs) {
            $migrationPaths = [...$migrationPaths, ...(glob($path . '/*.php.stub') ?: [])];
        }

        sort($migrationPaths);

        return array_values(array_unique(array_map(
            static fn (string $migrationPath): string => str($migrationPath)
                ->basename()
                ->replaceEnd('.php.stub', '')
                ->replaceEnd('.php', '')
                ->toString(),
            $migrationPaths,
        )));
    }

    /** @param array<string, string> $composerVersions */
    private function versionAudit(array $composerVersions): ?VersionAudit
    {
        try {
            return AuditInstalledVersionsAction::run($composerVersions);
        } catch (Throwable) {
            return null;
        }
    }
}
