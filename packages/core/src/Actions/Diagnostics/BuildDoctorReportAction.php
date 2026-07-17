<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Diagnostics;

use Capell\Core\Actions\Extensions\AuditExtensionContractsAction;
use Capell\Core\Actions\FindPageUrlsMissingSiteDomainsAction;
use Capell\Core\Actions\ResolvePublicPageByUrlAction;
use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\Core\Data\Diagnostics\DoctorReportData;
use Capell\Core\Data\PackageData;
use Capell\Core\Enums\Diagnostics\CapellInstallationState;
use Capell\Core\Enums\Diagnostics\DoctorCheckSeverity;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Support\Diagnostics\CapellRuntimeSchemaContract;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * @method static DoctorReportData run(bool $installSummary = false, bool $includePackageDoctors = true)
 */
final class BuildDoctorReportAction
{
    use AsFake;
    use AsObject;

    public function __construct(
        private readonly CapellRuntimeSchemaContract $runtimeSchema,
    ) {}

    public function handle(bool $installSummary = false, bool $includePackageDoctors = true): DoctorReportData
    {
        $checks = collect([
            $this->identified($this->checkRequiredTablesExist($installSummary), 'core.schema.required', DoctorCheckSeverity::Critical),
            $this->identified($this->checkMorphMap(), 'core.morph-map.complete', DoctorCheckSeverity::Critical),
            $this->identified($this->checkStorageDisksWritable(), 'core.storage.writable', DoctorCheckSeverity::Warning),
            $this->identified($this->checkSeeded(), 'core.seed-data.present', DoctorCheckSeverity::Critical),
            $this->identified($this->checkConfigFiles(), 'core.config.published', DoctorCheckSeverity::Info),
            $this->identified($this->checkManifestV3Contracts(), 'core.manifest-v3.valid', DoctorCheckSeverity::Warning),
            $this->identified($this->checkInstalledPackages(), 'core.packages.installed', DoctorCheckSeverity::Critical),
            $this->identified($this->checkCapellViteInputsIntegration(), 'core.assets.vite-inputs', DoctorCheckSeverity::Warning),
            $this->identified($this->checkGeneratedTailwindCss(), 'core.tailwind.generated', DoctorCheckSeverity::Warning),
            $this->identified($this->checkAdminUserAccess(), 'core.admin.access', DoctorCheckSeverity::Critical),
            $this->identified($this->checkHomepageRouteResolves(), 'core.route.homepage', DoctorCheckSeverity::Critical),
            $this->identified($this->checkDefaultThemeAndLayoutRecords(), 'core.defaults.theme-layout', DoctorCheckSeverity::Critical),
            $this->identified($this->checkPageUrlsHaveSiteDomains(), 'core.page-urls.site-domains', DoctorCheckSeverity::Critical),
        ]);

        if ($installSummary && $includePackageDoctors) {
            $checks = $checks->merge($this->installedPackageDoctorChecks());
        }

        return new DoctorReportData(
            status: $checks->every(fn (DoctorCheckResultData $check): bool => $check->passed) ? 'passed' : 'failed',
            checks: $checks->values(),
        );
    }

    private function identified(
        DoctorCheckResultData $check,
        string $id,
        DoctorCheckSeverity $severity,
    ): DoctorCheckResultData {
        return new DoctorCheckResultData(
            label: $check->label,
            passed: $check->passed,
            message: $check->message,
            remediation: $check->remediation,
            id: $id,
            severity: $severity,
            evidence: $check->evidence,
        );
    }

    /**
     * @return Collection<int, DoctorCheckResultData>
     */
    private function installedPackageDoctorChecks(): Collection
    {
        return CapellCore::getPackages(withoutCore: true)
            ->filter(fn (PackageData $package): bool => $package->isInstalled())
            ->map(fn (PackageData $package): ?string => $package->getDoctorCommand())
            ->filter(fn (?string $command): bool => is_string($command)
                && $command !== ''
                && $command !== 'capell:doctor'
                && array_key_exists($command, Artisan::all()))
            ->flatMap(fn (string $command): array => $this->runPackageDoctorCommand($command))
            ->values();
    }

    /**
     * @return array<int, DoctorCheckResultData>
     */
    private function runPackageDoctorCommand(string $command): array
    {
        try {
            $output = new BufferedOutput;

            Artisan::call($command, ['--json' => true], $output);

            $decoded = json_decode($output->fetch(), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $throwable) {
            return [
                new DoctorCheckResultData(
                    label: sprintf('Package doctor: %s', $command),
                    passed: false,
                    message: $throwable->getMessage(),
                    remediation: sprintf('Run php artisan %s directly for package diagnostics.', $command),
                    id: 'package-doctor.execution-failed',
                    severity: DoctorCheckSeverity::Critical,
                    evidence: ['command' => $command],
                ),
            ];
        }

        if (! is_array($decoded) || ! is_array($decoded['checks'] ?? null)) {
            return [
                new DoctorCheckResultData(
                    label: sprintf('Package doctor: %s', $command),
                    passed: false,
                    message: 'Package doctor did not return a valid JSON check report.',
                    remediation: sprintf('Run php artisan %s --json and inspect the output.', $command),
                    id: 'package-doctor.invalid-contract',
                    severity: DoctorCheckSeverity::Critical,
                    evidence: ['command' => $command],
                ),
            ];
        }

        $invalidContract = collect($decoded['checks'])->contains(function (mixed $check): bool {
            if (! is_array($check)) {
                return true;
            }

            $id = $check['id'] ?? null;
            $severity = $check['severity'] ?? null;

            return ! is_string($id)
                || preg_match('/^[a-z0-9]+(?:[.-][a-z0-9]+)*$/', $id) !== 1
                || ! is_string($severity)
                || DoctorCheckSeverity::tryFrom($severity) === null;
        });

        if ($invalidContract) {
            return [new DoctorCheckResultData(
                label: sprintf('Package doctor: %s', $command),
                passed: false,
                message: 'Package doctor checks must provide stable IDs and native severity.',
                remediation: sprintf('Update php artisan %s --json to the doctor check contract.', $command),
                id: 'package-doctor.invalid-contract',
                severity: DoctorCheckSeverity::Critical,
                evidence: ['command' => $command],
            )];
        }

        return collect($decoded['checks'])
            ->filter(fn (mixed $check): bool => is_array($check))
            ->map(fn (array $check): DoctorCheckResultData => new DoctorCheckResultData(
                label: (string) ($check['label'] ?? sprintf('Package doctor: %s', $command)),
                passed: (bool) ($check['passed'] ?? false),
                message: (string) ($check['message'] ?? ''),
                remediation: isset($check['remediation']) ? (string) $check['remediation'] : null,
                id: (string) $check['id'],
                severity: DoctorCheckSeverity::from((string) $check['severity']),
                evidence: is_array($check['evidence'] ?? null) ? $check['evidence'] : [],
            ))
            ->values()
            ->all();
    }

    private function checkRequiredTablesExist(bool $installSummary): DoctorCheckResultData
    {
        $missingTables = $this->runtimeSchema->missingTables();
        $installationState = ResolveCapellInstallationStateAction::run();
        $installationIsComplete = $installationState === CapellInstallationState::Installed
            || ($installSummary && $missingTables === []);

        if (! $installationIsComplete) {
            return new DoctorCheckResultData(
                label: 'Required tables exist',
                passed: false,
                message: $missingTables !== []
                    ? sprintf('Missing tables: %s.', implode(', ', $missingTables))
                    : 'Core lifecycle state does not record a complete installation.',
                remediation: 'Run php artisan migrate.',
                id: 'core.schema.required',
                severity: DoctorCheckSeverity::Critical,
                evidence: [
                    'installation_state' => $installationState->value,
                    'missing_tables' => $missingTables,
                    'required_tables' => $this->runtimeSchema->requiredTables(),
                ],
            );
        }

        return new DoctorCheckResultData(
            label: 'Required tables exist',
            passed: true,
            message: $installationState === CapellInstallationState::Installed
                ? 'All required tables exist.'
                : 'All required tables exist; core lifecycle completion is pending the final installer step.',
            id: 'core.schema.required',
            severity: DoctorCheckSeverity::Critical,
            evidence: [
                'installation_state' => $installationState->value,
                'missing_tables' => [],
                'required_tables' => $this->runtimeSchema->requiredTables(),
            ],
        );
    }

    private function checkPageUrlsHaveSiteDomains(): DoctorCheckResultData
    {
        if (
            ! Schema::hasTable('page_urls')
            || ! Schema::hasTable('site_domains')
            || ! Schema::hasTable('sites')
            || ! Schema::hasTable('languages')
        ) {
            return new DoctorCheckResultData(
                label: 'Page URLs have site domains',
                passed: true,
                message: 'Skipped until page URL, site domain, site, and language tables exist.',
            );
        }

        $missingPageUrls = FindPageUrlsMissingSiteDomainsAction::run();

        if ($missingPageUrls->isEmpty()) {
            return new DoctorCheckResultData(
                label: 'Page URLs have site domains',
                passed: true,
                message: 'Every page URL has a matching active site domain.',
            );
        }

        $examples = $missingPageUrls
            ->take(5)
            ->map(fn (PageUrl $pageUrl): string => sprintf(
                '#%d site:%d language:%d',
                $pageUrl->getKey(),
                $pageUrl->site_id,
                $pageUrl->language_id,
            ))
            ->implode(', ');

        return new DoctorCheckResultData(
            label: 'Page URLs have site domains',
            passed: false,
            message: sprintf(
                '%d page URL(s) are missing matching active site domains. Examples: %s.',
                $missingPageUrls->count(),
                $examples,
            ),
            remediation: 'Run php artisan capell:doctor --repair-page-url-domains or rerun the relevant site/demo installer.',
        );
    }

    private function checkMorphMap(): DoctorCheckResultData
    {
        $currentMorphMap = Relation::morphMap();

        $expectedEntries = collect(CapellCore::getModels())
            ->mapWithKeys(fn (string $modelClass, string $name): array => [Str::snake($name) => $modelClass])
            ->all();

        $missingFromMorphMap = array_filter(
            $expectedEntries,
            fn (string $modelClass, string $alias): bool => ! array_key_exists($alias, $currentMorphMap),
            ARRAY_FILTER_USE_BOTH,
        );

        if ($missingFromMorphMap !== []) {
            return new DoctorCheckResultData(
                label: 'Morph map is complete',
                passed: false,
                message: sprintf('Morph map missing aliases: %s.', implode(', ', array_keys($missingFromMorphMap))),
                remediation: 'Check your Capell service providers and cached config.',
            );
        }

        return new DoctorCheckResultData('Morph map is complete', true, 'Core morph map is complete.');
    }

    private function checkStorageDisksWritable(): DoctorCheckResultData
    {
        $assetsDisk = config('capell.assets.disk', 'local');
        $diskNames = array_values(array_unique(array_filter([
            is_string($assetsDisk) ? $assetsDisk : null,
        ], fn (mixed $value): bool => $value !== null)));

        $failedDisks = [];
        $skippedDisks = [];

        foreach ($diskNames as $diskName) {
            $filesystemsConfig = config('filesystems.disks', []);
            if (! isset($filesystemsConfig[$diskName])) {
                $skippedDisks[] = $diskName;

                continue;
            }

            try {
                $testFile = '.capell-doctor-probe-' . bin2hex(random_bytes(8));
                $disk = Storage::disk($diskName);
                $disk->put($testFile, '');
                $disk->delete($testFile);
            } catch (Throwable) {
                $failedDisks[] = $diskName;
            }
        }

        if ($failedDisks !== []) {
            return new DoctorCheckResultData(
                label: 'Storage disks are writable',
                passed: false,
                message: sprintf('Disk(s) not writable: %s.', implode(', ', $failedDisks)),
                remediation: 'Check storage configuration and filesystem permissions.',
            );
        }

        $checkedDisks = array_diff($diskNames, $skippedDisks);

        if ($skippedDisks !== []) {
            return new DoctorCheckResultData(
                label: 'Storage disks are writable',
                passed: true,
                message: sprintf(
                    'Disks checked (some not configured in filesystems.disks): %s.',
                    $checkedDisks !== [] ? implode(', ', $checkedDisks) : 'none',
                ),
            );
        }

        return new DoctorCheckResultData('Storage disks are writable', true, 'All configured storage disks are writable.');
    }

    private function checkSeeded(): DoctorCheckResultData
    {
        $issues = [];

        try {
            if (Site::query()->count() === 0) {
                $issues[] = 'No sites found';
            }
        } catch (Throwable) {
            $issues[] = 'Could not query sites table';
        }

        try {
            if (Language::query()->count() === 0) {
                $issues[] = 'No languages found';
            }
        } catch (Throwable) {
            $issues[] = 'Could not query languages table';
        }

        if ($issues !== []) {
            return new DoctorCheckResultData(
                label: 'Seed data is present',
                passed: false,
                message: implode('; ', $issues) . '.',
                remediation: 'Run php artisan capell:install.',
            );
        }

        return new DoctorCheckResultData('Seed data is present', true, 'At least one site and language exist.');
    }

    private function checkConfigFiles(): DoctorCheckResultData
    {
        $capellConfigFiles = glob(config_path('capell*.php'));

        if ($capellConfigFiles === false || $capellConfigFiles === []) {
            return new DoctorCheckResultData('Config files', true, 'No published Capell config files detected (defaults in use).');
        }

        $fileNames = array_map(basename(...), $capellConfigFiles);

        return new DoctorCheckResultData(
            label: 'Config files',
            passed: true,
            message: sprintf('Published config file(s) detected: %s.', implode(', ', $fileNames)),
            remediation: 'Keep published config files in sync when upgrading.',
        );
    }

    private function checkManifestV3Contracts(): DoctorCheckResultData
    {
        $results = AuditExtensionContractsAction::run();
        $errors = array_filter($results, static fn (array $result): bool => $result['severity'] === 'error');

        if ($errors !== []) {
            return new DoctorCheckResultData(
                label: 'Manifest v3 contracts',
                passed: false,
                message: sprintf('%d manifest contract error(s).', count($errors)),
                remediation: 'Run php artisan capell:extension-audit.',
            );
        }

        $warnings = array_filter($results, static fn (array $result): bool => $result['severity'] === 'warning');

        if ($warnings !== []) {
            return new DoctorCheckResultData(
                label: 'Manifest v3 contracts',
                passed: true,
                message: sprintf('%d manifest contract warning(s).', count($warnings)),
                remediation: 'Run php artisan capell:extension-audit.',
            );
        }

        return new DoctorCheckResultData('Manifest v3 contracts', true, 'No extension manifest contract errors found.');
    }

    private function checkInstalledPackages(): DoctorCheckResultData
    {
        $installedPackages = CapellCore::getPackages(withoutCore: false)
            ->filter(fn (PackageData $package): bool => $package->isInstalled())
            ->keys()
            ->values();

        if ($installedPackages->isEmpty()) {
            return new DoctorCheckResultData(
                label: 'Installed Capell packages',
                passed: false,
                message: 'No installed Capell packages were detected.',
                remediation: 'Run php artisan capell:install and choose the required packages.',
            );
        }

        return new DoctorCheckResultData(
            label: 'Installed Capell packages',
            passed: true,
            message: sprintf('%d package(s) are marked installed.', $installedPackages->count()),
        );
    }

    private function checkCapellViteInputsIntegration(): DoctorCheckResultData
    {
        $manifestPath = base_path('bootstrap/cache/capell-vite-inputs.json');

        if (! is_file($manifestPath)) {
            return new DoctorCheckResultData(
                label: 'Capell Vite inputs are integrated',
                passed: true,
                message: 'No generated Capell Vite inputs require integration.',
            );
        }

        try {
            $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $manifest = null;
        }

        if (! is_array($manifest) || ! is_array($manifest['inputs'] ?? null)) {
            return new DoctorCheckResultData(
                label: 'Capell Vite inputs are integrated',
                passed: false,
                message: 'The generated Capell Vite input manifest is invalid.',
                remediation: 'Run php artisan capell:frontend-after-install --apply to regenerate it.',
            );
        }

        if ($manifest['inputs'] === []) {
            return new DoctorCheckResultData(
                label: 'Capell Vite inputs are integrated',
                passed: true,
                message: 'The generated Capell Vite input manifest has no application entries.',
            );
        }

        $viteConfigPath = collect(['vite.config.js', 'vite.config.mjs', 'vite.config.ts'])
            ->map(static fn (string $file): string => base_path($file))
            ->first(static fn (string $file): bool => is_file($file));

        if (! is_string($viteConfigPath) || ! str_contains((string) file_get_contents($viteConfigPath), 'capellViteInputs')) {
            return new DoctorCheckResultData(
                label: 'Capell Vite inputs are integrated',
                passed: false,
                message: 'Generated Capell Vite entries are not included in the application Vite configuration.',
                remediation: "Import capellViteInputs from '@capell/frontend/capell-vite-inputs' and spread ...capellViteInputs() into the Laravel Vite input array.",
            );
        }

        return new DoctorCheckResultData(
            label: 'Capell Vite inputs are integrated',
            passed: true,
            message: sprintf('%d generated Capell Vite input(s) are integrated.', count($manifest['inputs'])),
        );
    }

    private function checkGeneratedTailwindCss(): DoctorCheckResultData
    {
        $paths = array_values(array_unique(array_filter([
            resource_path('css/capell/frontend.css'),
            public_path('vendor/capell-frontend/css/frontend.css'),
        ], fn (string $path): bool => $path !== '')));

        foreach ($paths as $path) {
            if (is_file($path)) {
                return new DoctorCheckResultData(
                    label: 'Generated frontend Tailwind CSS',
                    passed: true,
                    message: sprintf('Found %s.', str_replace(base_path() . DIRECTORY_SEPARATOR, '', $path)),
                );
            }
        }

        if (! app()->bound('capell.tailwind.generator')) {
            return new DoctorCheckResultData(
                label: 'Generated frontend Tailwind CSS',
                passed: true,
                message: 'No frontend Tailwind generator is registered for this install.',
            );
        }

        return new DoctorCheckResultData(
            label: 'Generated frontend Tailwind CSS',
            passed: false,
            message: 'No generated Capell frontend CSS file was found.',
            remediation: 'Run php artisan capell:frontend-install, then npm run build if the application Vite bundle is not current.',
        );
    }

    private function checkAdminUserAccess(): DoctorCheckResultData
    {
        return CheckAdminPanelAccessAction::run();
    }

    private function checkHomepageRouteResolves(): DoctorCheckResultData
    {
        // Exercise the real runtime resolver rather than a loose DB lookup: a loose
        // query can report the homepage "resolves" while the public request path
        // (page-type accessible/enabled gates, published date, morph map) rejects it,
        // producing a themed 404. Calling ResolvePublicPageByUrlAction here makes a
        // green check guarantee the page actually renders for anonymous visitors.
        try {
            $site = Site::query()->default()->with('language')->first() ?? Site::query()->with('language')->first();
            $language = $site?->language;

            $resolvedPage = $site instanceof Site && $language instanceof Language
                ? ResolvePublicPageByUrlAction::run($site, $language, '/')->page
                : null;
        } catch (Throwable) {
            $resolvedPage = null;
        }

        $routeRegistered = Route::has('capell.home') || Route::has('capell.frontend') || Route::has('frontend');

        $resolvedToRealPage = $resolvedPage instanceof Page && ! $resolvedPage->isErrorPage();

        if (! $resolvedToRealPage) {
            return new DoctorCheckResultData(
                label: 'Homepage route resolves',
                passed: false,
                message: 'The public page resolver returned no page (or the error page) for "/".',
                remediation: 'Confirm the homepage page type is enabled and accessible, the page is published, and page URLs were generated.',
            );
        }

        return new DoctorCheckResultData(
            label: 'Homepage route resolves',
            passed: true,
            message: $routeRegistered
                ? sprintf('Homepage #%d resolves through the public resolver and a frontend route is registered.', $resolvedPage->getKey())
                : sprintf('Homepage #%d resolves through the public resolver.', $resolvedPage->getKey()),
        );
    }

    private function checkDefaultThemeAndLayoutRecords(): DoctorCheckResultData
    {
        $issues = [];

        if (Schema::hasTable('themes') && ! resolve(ConnectionResolverInterface::class)->table('themes')->where('default', true)->exists()) {
            $issues[] = 'No default theme';
        }

        if (Schema::hasTable('layouts') && ! Layout::query()->default()->exists()) {
            $issues[] = 'No default layout';
        }

        if ($issues !== []) {
            return new DoctorCheckResultData(
                label: 'Default theme and layout records',
                passed: false,
                message: implode('; ', $issues) . '.',
                remediation: 'Rerun theme setup and ensure default theme/layout fixtures are seeded.',
            );
        }

        return new DoctorCheckResultData('Default theme and layout records', true, 'Default theme and layout records are present.');
    }
}
