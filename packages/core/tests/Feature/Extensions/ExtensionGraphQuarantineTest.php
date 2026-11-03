<?php

declare(strict_types=1);

use Capell\Core\Actions\EnablePackageAction;
use Capell\Core\Actions\Extensions\LiftExtensionQuarantineAction;
use Capell\Core\Actions\Extensions\QuarantineExtensionsAction;
use Capell\Core\Actions\Extensions\VerifyInstalledExtensionAction;
use Capell\Core\Data\Extensions\ExtensionVerificationIssueData;
use Capell\Core\Data\Extensions\ExtensionVerificationReportData;
use Capell\Core\Exceptions\ExtensionVerificationFailedException;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\ExtensionQuarantine;
use Capell\Core\Support\Diagnostics\Checks\ExtensionVerificationCheck;
use Capell\Core\Support\Packages\InstalledRuntimeLifecycle;
use Capell\Core\Tests\Support\RuntimeLifecycleFixture;
use Carbon\CarbonImmutable;
use Illuminate\Testing\PendingCommand;

afterEach(fn () => VerifyInstalledExtensionAction::clearFake());

it('records every package in the changed set against the package that failed', function (): void {
    QuarantineExtensionsAction::run(['vendor/top-level', 'vendor/dependency'], 'vendor/dependency');

    $records = ExtensionQuarantine::query()->orderBy('composer_name')->get();
    expect($records->pluck('composer_name')->all())->toBe(['vendor/dependency', 'vendor/top-level'])
        ->and($records->pluck('failing_package')->unique()->all())->toBe(['vendor/dependency'])
        ->and($records->first()?->quarantined_set)->toBe(['vendor/dependency', 'vendor/top-level'])
        ->and(ExtensionQuarantine::isQuarantined('vendor/top-level'))->toBeTrue()
        ->and(ExtensionQuarantine::isQuarantined('vendor/unrelated'))->toBeFalse();

    expect(LiftExtensionQuarantineAction::run(['vendor/top-level', 'vendor/dependency']))->toBe(2)
        ->and(ExtensionQuarantine::quarantinedNames())->toBe([]);
});

it('refuses to enable a quarantined package even when verification is lenient', function (): void {
    config()->set('capell.extensions.verification.strict', false);
    $name = 'vendor/quarantined-enable';
    CapellCore::registerPackage($name);
    CapellCore::markPackageInstalled($name);
    CapellCore::markPackageDisabled($name);
    QuarantineExtensionsAction::run([$name], 'vendor/failed-dependency');
    VerifyInstalledExtensionAction::shouldRun()->never();

    expect(fn () => EnablePackageAction::run(CapellCore::getPackage($name)))
        ->toThrow(ExtensionVerificationFailedException::class, 'capell:extensions:release-quarantine ' . $name);
    expect(CapellCore::isPackageEnabled($name))->toBeFalse();
});

it('does not boot the providers of a quarantined package', function (): void {
    QuarantineExtensionsAction::run([RuntimeLifecycleFixture::$packageName], 'vendor/failed-dependency');
    CapellCore::registerPackage(RuntimeLifecycleFixture::$packageName, serviceProviderClass: RuntimeLifecycleFixture::class);
    CapellCore::forcePackageInstalled(RuntimeLifecycleFixture::$packageName, true);
    $provider = app()->register(RuntimeLifecycleFixture::class);

    resolve(InstalledRuntimeLifecycle::class)->refresh();
    expect($provider->registrations)->toBe([]);

    LiftExtensionQuarantineAction::run([RuntimeLifecycleFixture::$packageName]);
    resolve(InstalledRuntimeLifecycle::class)->refresh();
    expect($provider->registrations)->toBe(['runtime']);
});

it('reports quarantined packages, the failing package and the set in doctor', function (): void {
    QuarantineExtensionsAction::run(['vendor/top-level', 'vendor/dependency'], 'vendor/dependency');

    $result = resolve(ExtensionVerificationCheck::class)->check();

    expect($result->passed)->toBeFalse()
        ->and($result->message)->toContain(__('capell-core::extensions.quarantine.doctor', [
            'package' => 'vendor/top-level',
            'failing' => 'vendor/dependency',
            'set' => 'vendor/dependency, vendor/top-level',
        ]));
});

it('releases a quarantined package only once it verifies', function (): void {
    $name = 'vendor/quarantined-release';
    QuarantineExtensionsAction::run([$name, 'vendor/sibling'], $name);
    $issue = new ExtensionVerificationIssueData('manifest_missing', 'Manifest missing.', 'Restore the manifest.');
    VerifyInstalledExtensionAction::shouldRun()->once()->with($name)->andReturn(new ExtensionVerificationReportData($name, null, null, null, false, [$issue], [], CarbonImmutable::now()));

    $command = $this->artisan('capell:extensions:release-quarantine', ['package' => $name]);
    assert($command instanceof PendingCommand);
    $command->expectsOutputToContain('Restore the manifest.')
        ->assertFailed()
        ->run();
    expect(ExtensionQuarantine::isQuarantined($name))->toBeTrue();

    VerifyInstalledExtensionAction::clearFake();
    VerifyInstalledExtensionAction::shouldRun()->once()->with($name)->andReturn(new ExtensionVerificationReportData($name, '1.0.0', null, null, true, [], [], CarbonImmutable::now()));
    $command = $this->artisan('capell:extensions:release-quarantine', ['package' => $name]);
    assert($command instanceof PendingCommand);
    $command->expectsOutputToContain(__('capell-core::extensions.quarantine.released', ['package' => $name]))
        ->assertSuccessful()
        ->run();

    expect(ExtensionQuarantine::isQuarantined($name))->toBeFalse()
        ->and(ExtensionQuarantine::isQuarantined('vendor/sibling'))->toBeTrue();
});

it('reports a package that is not quarantined without verifying it', function (): void {
    VerifyInstalledExtensionAction::shouldRun()->never();

    $command = $this->artisan('capell:extensions:release-quarantine', ['package' => 'vendor/not-held']);
    assert($command instanceof PendingCommand);
    $command->expectsOutputToContain(__('capell-core::extensions.quarantine.not_quarantined', ['package' => 'vendor/not-held']))
        ->assertSuccessful()
        ->run();
});
