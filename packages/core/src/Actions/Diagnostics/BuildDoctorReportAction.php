<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Diagnostics;

use Capell\Core\Contracts\DoctorCheck;
use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\Core\Data\Diagnostics\DoctorReportData;
use Capell\Core\Data\PackageData;
use Capell\Core\Enums\Diagnostics\DoctorCheckSeverity;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Diagnostics\Checks\AdminUserAccessCheck;
use Capell\Core\Support\Diagnostics\Checks\ConfigFilesCheck;
use Capell\Core\Support\Diagnostics\Checks\DefaultThemeAndLayoutCheck;
use Capell\Core\Support\Diagnostics\Checks\GeneratedTailwindCssCheck;
use Capell\Core\Support\Diagnostics\Checks\HomepageRouteCheck;
use Capell\Core\Support\Diagnostics\Checks\InstalledPackagesCheck;
use Capell\Core\Support\Diagnostics\Checks\ManifestContractsCheck;
use Capell\Core\Support\Diagnostics\Checks\MorphMapCheck;
use Capell\Core\Support\Diagnostics\Checks\PageUrlSiteDomainsCheck;
use Capell\Core\Support\Diagnostics\Checks\RequiredTablesCheck;
use Capell\Core\Support\Diagnostics\Checks\SeedDataCheck;
use Capell\Core\Support\Diagnostics\Checks\StorageDisksWritableCheck;
use Capell\Core\Support\Diagnostics\Checks\ViteInputsCheck;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
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

    /** @var list<class-string<DoctorCheck>> */
    private const array CORE_CHECKS = [
        RequiredTablesCheck::class,
        MorphMapCheck::class,
        StorageDisksWritableCheck::class,
        SeedDataCheck::class,
        ConfigFilesCheck::class,
        ManifestContractsCheck::class,
        InstalledPackagesCheck::class,
        ViteInputsCheck::class,
        GeneratedTailwindCssCheck::class,
        AdminUserAccessCheck::class,
        HomepageRouteCheck::class,
        DefaultThemeAndLayoutCheck::class,
        PageUrlSiteDomainsCheck::class,
    ];

    public function handle(bool $installSummary = false, bool $includePackageDoctors = true): DoctorReportData
    {
        $checks = collect(self::CORE_CHECKS)
            ->map(fn (string $check): DoctorCheckResultData => resolve($check)->check($installSummary));

        if ($installSummary && $includePackageDoctors) {
            $checks = $checks->merge($this->installedPackageDoctorChecks());
        }

        return new DoctorReportData(
            status: $checks->every(fn (DoctorCheckResultData $check): bool => $check->passed) ? 'passed' : 'failed',
            checks: $checks->values(),
        );
    }

    /** @return Collection<int, DoctorCheckResultData> */
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

    /** @return array<int, DoctorCheckResultData> */
    private function runPackageDoctorCommand(string $command): array
    {
        try {
            $output = new BufferedOutput;
            Artisan::call($command, ['--json' => true], $output);
            $decoded = json_decode($output->fetch(), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $throwable) {
            return [new DoctorCheckResultData(
                label: sprintf('Package doctor: %s', $command),
                passed: false,
                message: $throwable->getMessage(),
                remediation: sprintf('Run php artisan %s directly for package diagnostics.', $command),
                id: 'package-doctor.execution-failed',
                severity: DoctorCheckSeverity::Critical,
                evidence: ['command' => $command],
            )];
        }

        if (! is_array($decoded) || ! is_array($decoded['checks'] ?? null)) {
            return [new DoctorCheckResultData(
                label: sprintf('Package doctor: %s', $command),
                passed: false,
                message: 'Package doctor did not return a valid JSON check report.',
                remediation: sprintf('Run php artisan %s --json and inspect the output.', $command),
                id: 'package-doctor.invalid-contract',
                severity: DoctorCheckSeverity::Critical,
                evidence: ['command' => $command],
            )];
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
}
