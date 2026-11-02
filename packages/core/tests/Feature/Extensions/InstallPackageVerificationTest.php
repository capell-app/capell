<?php

declare(strict_types=1);

use Capell\Core\Actions\Extensions\VerifyInstalledExtensionAction;
use Capell\Core\Actions\InstallPackageAction;
use Capell\Core\Data\Extensions\ExtensionVerificationIssueData;
use Capell\Core\Data\Extensions\ExtensionVerificationReportData;
use Capell\Core\Enums\ExtensionStatusEnum;
use Capell\Core\Exceptions\ExtensionVerificationFailedException;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\CapellExtension;
use Capell\Core\Tests\Support\Install\RecordingInstallProgressReporter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

afterEach(fn () => VerifyInstalledExtensionAction::clearFake());

// The suite runs lenient by default (phpunit.xml); these tests are about strict enforcement.
beforeEach(function (): void {
    config()->set('capell.extensions.verification.strict', true);
});

it('refuses an invalid package and records the next action', function (): void {
    $name = 'vendor/verification-install';
    CapellCore::registerPackage($name);
    $issue = new ExtensionVerificationIssueData('manifest_missing', 'Manifest missing.', 'Restore the manifest.');
    $report = new ExtensionVerificationReportData($name, null, null, null, false, [$issue], [], CarbonImmutable::now());
    VerifyInstalledExtensionAction::shouldRun()->once()->with($name)->andReturn($report);

    expect(fn () => InstallPackageAction::run(CapellCore::getPackage($name)))
        ->toThrow(ExtensionVerificationFailedException::class, 'Restore the manifest.');
    $extension = CapellExtension::query()->where('composer_name', $name)->firstOrFail();
    expect(CapellCore::isPackageInstalled($name))->toBeFalse()
        ->and($extension->status)->toBe(ExtensionStatusEnum::Failed)
        ->and($extension->metadata['install_error'] ?? null)->toContain('Restore the manifest.');
});

it('logs failures and installs in lenient mode', function (): void {
    $name = 'vendor/verification-lenient';
    config()->set('capell.extensions.verification.strict', false);
    CapellCore::registerPackage($name);
    $issue = new ExtensionVerificationIssueData('manifest_missing', 'Manifest missing.', 'Restore the manifest.');
    VerifyInstalledExtensionAction::shouldRun()->once()->with($name)->andReturn(new ExtensionVerificationReportData($name, null, null, null, false, [$issue], [], CarbonImmutable::now()));
    Log::shouldReceive('warning')->once()->with('capell-core: extension.verification_failed', ['package' => $name, 'failures' => ['manifest_missing']]);

    InstallPackageAction::run(CapellCore::getPackage($name));
    expect(CapellCore::isPackageInstalled($name))->toBeTrue();
});

it('skips verification for trusted foundation packages', function (): void {
    CapellCore::registerPackage('capell-app/admin');
    VerifyInstalledExtensionAction::shouldRun()->never();
    InstallPackageAction::run(CapellCore::getPackage('capell-app/admin'));
    expect(CapellCore::isPackageInstalled('capell-app/admin'))->toBeTrue();
});

it('continues the normal install and reports a successful verification', function (): void {
    $name = 'vendor/verification-passing';
    CapellCore::registerPackage($name);
    VerifyInstalledExtensionAction::shouldRun()->once()->with($name)->andReturn(new ExtensionVerificationReportData($name, null, null, null, true, [], [], CarbonImmutable::now()));
    $reporter = new RecordingInstallProgressReporter;
    InstallPackageAction::run(CapellCore::getPackage($name), reporter: $reporter);
    expect(CapellCore::isPackageInstalled($name))->toBeTrue()
        ->and($reporter->lines)->toContain(__('capell::extensions.verification.passed', ['package' => $name]));
});

it('does not expose a manifest validation path in the refusal', function (): void {
    $name = 'vendor/verification-private';
    CapellCore::registerPackage($name);
    $issue = new ExtensionVerificationIssueData('manifest_invalid', 'Invalid /private/path/manifest.', 'Correct the manifest.');
    VerifyInstalledExtensionAction::shouldRun()->once()->with($name)->andReturn(new ExtensionVerificationReportData($name, null, null, null, false, [$issue], [], CarbonImmutable::now()));
    try {
        InstallPackageAction::run(CapellCore::getPackage($name));
        test()->fail('Expected verification to refuse the install.');
    } catch (ExtensionVerificationFailedException $extensionVerificationFailedException) {
        expect($extensionVerificationFailedException->getMessage())->toContain($name, 'Correct the manifest.')->not->toContain('/private/path');
    }
});
