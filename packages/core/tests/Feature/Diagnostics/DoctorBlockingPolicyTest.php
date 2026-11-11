<?php

declare(strict_types=1);

use Capell\Core\Actions\Diagnostics\BuildDoctorReportAction;
use Capell\Core\Contracts\DoctorCheck;
use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\Core\Enums\Diagnostics\DoctorCheckOutcome;
use Capell\Core\Enums\Diagnostics\DoctorCheckSeverity;
use Capell\Core\Support\Diagnostics\Checks\AbstractDoctorCheck;
use Capell\Core\Support\Diagnostics\Checks\ConfigFilesCheck;
use Capell\Core\Support\Diagnostics\Checks\DatabaseBackupBinariesCheck;
use Capell\Core\Support\Diagnostics\Checks\ManifestContractsCheck;
use Capell\Core\Support\Diagnostics\Checks\RuntimeToolingCheck;
use Capell\Core\Support\Diagnostics\Checks\SharedCacheStoreCheck;

/**
 * Every doctor check that could fail the doctor before warnings existed still
 * does. A check may only be demoted to a warning by adding it here with the
 * reason it cannot stop the site running, so a demotion is always a deliberate,
 * reviewed change to this list.
 *
 * @return array<class-string<AbstractDoctorCheck>, array{severity: DoctorCheckSeverity, reason: string}>
 */
function deliberatelyNonBlockingDoctorChecks(): array
{
    return [
        ManifestContractsCheck::class => ['severity' => DoctorCheckSeverity::Warning, 'reason' => 'Contract warnings in extension manifests; errors escalate to Critical and the check never failed the doctor for warnings.'],
        DatabaseBackupBinariesCheck::class => ['severity' => DoctorCheckSeverity::Warning, 'reason' => 'Missing dump binaries stop backups, not the running site.'],
        RuntimeToolingCheck::class => ['severity' => DoctorCheckSeverity::Warning, 'reason' => 'Optional tooling for extension installs and rebuilds; the running site does not use it.'],
        SharedCacheStoreCheck::class => ['severity' => DoctorCheckSeverity::Warning, 'reason' => 'A non-shared cache is correct on one server; advice for multi-node hosting.'],
        ConfigFilesCheck::class => ['severity' => DoctorCheckSeverity::Info, 'reason' => 'Informational: always passes.'],
    ];
}

it('keeps every registered doctor check blocking unless it is deliberately listed as non-blocking', function (): void {
    $registered = collect(BuildDoctorReportAction::CORE_CHECKS)
        ->merge(collect(app()->tagged(DoctorCheck::TAG))->map(fn (DoctorCheck $check): string => $check::class))
        ->unique()
        ->filter(fn (string $class): bool => is_subclass_of($class, AbstractDoctorCheck::class))
        ->values();

    expect($registered)->not->toBeEmpty();

    $declared = $registered->mapWithKeys(function (string $class): array {
        $severity = new ReflectionMethod($class, 'severity');

        return [$class => $severity->invoke(resolve($class))];
    });

    $nonBlocking = $declared->reject(fn (DoctorCheckSeverity $severity): bool => $severity === DoctorCheckSeverity::Critical);
    $allowed = deliberatelyNonBlockingDoctorChecks();

    expect($nonBlocking->keys()->sort()->values()->all())->toBe(collect(array_keys($allowed))->sort()->values()->all());

    foreach ($allowed as $class => $policy) {
        expect($nonBlocking[$class])->toBe($policy['severity'])
            ->and($policy['reason'])->not->toBeEmpty();
    }
});

it('stops the doctor for a failure reported by a tagged third-party check whatever severity it declares', function (): void {
    $check = new class implements DoctorCheck
    {
        public function check(bool $installSummary = false): DoctorCheckResultData
        {
            return new DoctorCheckResultData('Third party', false, 'It is broken.', 'Fix it.', 'thirdparty.broken', DoctorCheckSeverity::Warning);
        }
    };
    app()->instance('test.third-party-doctor-check', $check);
    app()->tag(['test.third-party-doctor-check'], DoctorCheck::TAG);

    $result = BuildDoctorReportAction::run(includePackageDoctors: false)->checks->firstWhere('id', 'thirdparty.broken');

    expect($result?->outcome())->toBe(DoctorCheckOutcome::Failed)
        ->and($result?->severity)->toBe(DoctorCheckSeverity::Critical);
});
