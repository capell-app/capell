<?php

declare(strict_types=1);

use Capell\Core\Actions\Extensions\VerifyInstalledExtensionAction;
use Capell\Core\Data\Extensions\ExtensionVerificationIssueData;
use Capell\Core\Data\Extensions\ExtensionVerificationReportData;
use Capell\Core\Enums\Diagnostics\DoctorCheckSeverity;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Diagnostics\Checks\ExtensionVerificationCheck;
use Carbon\CarbonImmutable;

afterEach(fn () => VerifyInstalledExtensionAction::clearFake());

it('reports success when installed extensions pass verification', function (): void {
    $name = 'vendor/doctor-passing';
    CapellCore::registerPackage($name);
    CapellCore::markPackageInstalled($name);
    VerifyInstalledExtensionAction::shouldRun()->once()->with($name)->andReturn(new ExtensionVerificationReportData($name, null, null, null, true, [], [], CarbonImmutable::now()));
    $result = resolve(ExtensionVerificationCheck::class)->check();
    expect($result->passed)->toBeTrue()->and($result->severity)->toBe(DoctorCheckSeverity::Warning);
});

it('reports the first failure and action as a warning in normal and install summaries', function (bool $summary): void {
    $name = 'vendor/doctor-failing';
    CapellCore::registerPackage($name);
    CapellCore::markPackageInstalled($name);
    $issue = new ExtensionVerificationIssueData('manifest_missing', 'Manifest missing.', 'Restore the manifest.');
    VerifyInstalledExtensionAction::shouldRun()->once()->with($name)->andReturn(new ExtensionVerificationReportData($name, null, null, null, false, [$issue], [], CarbonImmutable::now()));
    $result = resolve(ExtensionVerificationCheck::class)->check($summary);
    expect($result->passed)->toBeFalse()->and($result->severity)->toBe(DoctorCheckSeverity::Warning)
        ->and($result->message)->toContain($name, 'Manifest missing.', 'Restore the manifest.');
})->with([false, true]);

it('does not expose a manifest validation path in doctor output', function (): void {
    $name = 'vendor/doctor-private';
    CapellCore::registerPackage($name);
    CapellCore::markPackageInstalled($name);
    $issue = new ExtensionVerificationIssueData('manifest_invalid', 'Invalid /private/path/manifest.', 'Correct the manifest.');
    VerifyInstalledExtensionAction::shouldRun()->once()->with($name)->andReturn(new ExtensionVerificationReportData($name, null, null, null, false, [$issue], [], CarbonImmutable::now()));
    expect(resolve(ExtensionVerificationCheck::class)->check()->message)
        ->toContain($name, 'Correct the manifest.')->not->toContain('/private/path');
});
