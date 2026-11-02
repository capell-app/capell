<?php

declare(strict_types=1);

use Capell\Core\Actions\EnablePackageAction;
use Capell\Core\Actions\Extensions\VerifyInstalledExtensionAction;
use Capell\Core\Data\Extensions\ExtensionVerificationIssueData;
use Capell\Core\Data\Extensions\ExtensionVerificationReportData;
use Capell\Core\Exceptions\ExtensionVerificationFailedException;
use Capell\Core\Facades\CapellCore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

afterEach(fn () => VerifyInstalledExtensionAction::clearFake());

it('refuses to enable an invalid package without changing its state', function (): void {
    $name = 'vendor/verification-enable';
    CapellCore::registerPackage($name);
    CapellCore::markPackageInstalled($name);
    CapellCore::markPackageDisabled($name);
    $issue = new ExtensionVerificationIssueData('manifest_missing', 'Manifest missing.', 'Restore the manifest.');
    VerifyInstalledExtensionAction::shouldRun()->once()->with($name)->andReturn(new ExtensionVerificationReportData($name, null, null, null, false, [$issue], [], CarbonImmutable::now()));
    expect(fn () => EnablePackageAction::run(CapellCore::getPackage($name)))
        ->toThrow(ExtensionVerificationFailedException::class, 'Restore the manifest.');
    expect(CapellCore::isPackageEnabled($name))->toBeFalse();
});

it('logs failures and enables in lenient mode', function (): void {
    $name = 'vendor/verification-enable-lenient';
    config()->set('capell.extensions.verification.strict', false);
    CapellCore::registerPackage($name);
    $issue = new ExtensionVerificationIssueData('manifest_missing', 'Manifest missing.', 'Restore the manifest.');
    VerifyInstalledExtensionAction::shouldRun()->once()->with($name)->andReturn(new ExtensionVerificationReportData($name, null, null, null, false, [$issue], [], CarbonImmutable::now()));
    Log::shouldReceive('warning')->once()->with('capell-core: extension.verification_failed', ['package' => $name, 'failures' => ['manifest_missing']]);
    EnablePackageAction::run(CapellCore::getPackage($name));
    expect(CapellCore::isPackageEnabled($name))->toBeTrue();
});

it('skips verification for trusted foundation packages when enabling', function (): void {
    CapellCore::registerPackage('capell-app/admin');
    VerifyInstalledExtensionAction::shouldRun()->never();
    EnablePackageAction::run(CapellCore::getPackage('capell-app/admin'));
    expect(CapellCore::isPackageEnabled('capell-app/admin'))->toBeTrue();
});

it('preflights all bundle members before enabling any of them', function (): void {
    foreach (['vendor/enable-first', 'vendor/enable-second', 'vendor/enable-bundle'] as $name) {
        CapellCore::registerPackage($name);
    }

    $bundle = CapellCore::getPackage('vendor/enable-bundle');
    $bundle->kind = 'bundle';
    $bundle->requirements = ['vendor/enable-first', 'vendor/enable-second'];

    $issue = new ExtensionVerificationIssueData('manifest_missing', 'Manifest missing.', 'Restore the manifest.');
    VerifyInstalledExtensionAction::shouldRun()->once()->with('vendor/enable-first')->andReturn(new ExtensionVerificationReportData('vendor/enable-first', null, null, null, true, [], [], CarbonImmutable::now()));
    VerifyInstalledExtensionAction::shouldRun()->once()->with('vendor/enable-second')->andReturn(new ExtensionVerificationReportData('vendor/enable-second', null, null, null, false, [$issue], [], CarbonImmutable::now()));
    expect(fn () => EnablePackageAction::run($bundle))->toThrow(ExtensionVerificationFailedException::class);
    expect(CapellCore::isPackageEnabled('vendor/enable-first'))->toBeFalse()
        ->and(CapellCore::isPackageEnabled('vendor/enable-second'))->toBeFalse();
});
