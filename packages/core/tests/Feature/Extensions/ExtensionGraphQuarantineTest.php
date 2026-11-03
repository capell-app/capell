<?php

declare(strict_types=1);

use Capell\Core\Actions\EnablePackageAction;
use Capell\Core\Actions\Extensions\HashExtensionContentsAction;
use Capell\Core\Actions\Extensions\LiftExtensionQuarantineAction;
use Capell\Core\Actions\Extensions\QuarantineExtensionsAction;
use Capell\Core\Actions\Extensions\VerifyInstalledExtensionAction;
use Capell\Core\Data\Extensions\ExtensionQuarantineEvidenceData;
use Capell\Core\Data\Extensions\ExtensionVerificationIssueData;
use Capell\Core\Data\Extensions\ExtensionVerificationReportData;
use Capell\Core\Exceptions\ExtensionVerificationFailedException;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\ExtensionQuarantine;
use Capell\Core\Support\Diagnostics\Checks\ExtensionVerificationCheck;
use Capell\Core\Support\Packages\ExtensionQuarantineGate;
use Capell\Core\Support\Packages\InstalledRuntimeLifecycle;
use Capell\Core\Tests\Support\LegacyEnableRuntimeFixture;
use Capell\Core\Tests\Support\RuntimeLifecycleFixture;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
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

it('refuses the legacy installed provider path for a quarantined package', function (): void {
    $name = LegacyEnableRuntimeFixture::$packageName;
    QuarantineExtensionsAction::run([$name], $name);
    CapellCore::registerPackage($name, serviceProviderClass: LegacyEnableRuntimeFixture::class);
    CapellCore::forcePackageInstalled($name, true);
    $provider = app()->register(LegacyEnableRuntimeFixture::class);
    expect($provider->calls)->toBe(0)->and($provider->bootCalls)->toBe(0);
});

it('denies non-core packages when the quarantine store cannot be read', function (): void {
    // Fail every statement that touches the quarantine table, including the schema probe.
    DB::listen(static function (QueryExecuted $query): void {
        $statement = strtolower($query->sql . ' ' . implode(' ', array_map(static fn (mixed $binding): string => is_scalar($binding) ? (string) $binding : '', $query->bindings)));
        throw_if(str_contains($statement, 'capell_extension_quarantines'), RuntimeException::class, 'unavailable');
    });
    resolve(ExtensionQuarantineGate::class)->forget();
    expect(resolve(ExtensionQuarantineGate::class)->allows('vendor/example'))->toBeFalse()
        ->and(resolve(ExtensionQuarantineGate::class)->allows('capell-app/core'))->toBeTrue();
    $result = resolve(ExtensionVerificationCheck::class)->check();
    expect($result->passed)->toBeFalse()->and($result->message)->toContain(__('capell-core::extensions.quarantine.unavailable'));
});

it('fails closed when the quarantine query fails after the table is found', function (): void {
    DB::listen(static function (QueryExecuted $query): void {
        throw_if(str_contains(strtolower($query->sql), 'capell_extension_quarantines') && str_starts_with(strtolower(ltrim($query->sql)), 'select'), RuntimeException::class, 'quarantine query failed');
    });

    expect(resolve(ExtensionQuarantineGate::class)->allows('vendor/example'))->toBeFalse();
    $result = resolve(ExtensionVerificationCheck::class)->check();
    expect($result->passed)->toBeFalse()->and($result->message)->toContain(__('capell-core::extensions.quarantine.unavailable'));
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

it('reads the quarantined set once per boot cycle and again after a quarantine write', function (): void {
    QuarantineExtensionsAction::run(['vendor/held'], 'vendor/held');
    $lookups = 0;
    DB::listen(static function (QueryExecuted $query) use (&$lookups): void {
        $sql = strtolower($query->sql);
        // Count reads of the quarantine rows, not the schema probe for the table.
        if (str_contains($sql, 'capell_extension_quarantines') && str_starts_with(ltrim($sql), 'select') && ! str_contains($sql, 'sqlite_master') && ! str_contains($sql, 'information_schema') && ! str_contains($sql, 'pragma')) {
            $lookups++;
        }
    });
    $gate = resolve(ExtensionQuarantineGate::class);

    expect($gate->allows('vendor/held'))->toBeFalse()
        ->and(resolve(ExtensionQuarantineGate::class)->allows('vendor/one'))->toBeTrue()
        ->and($gate->allows('vendor/two'))->toBeTrue()
        ->and($lookups)->toBe(1);

    LiftExtensionQuarantineAction::run(['vendor/held']);
    expect($gate->allows('vendor/held'))->toBeTrue()->and($lookups)->toBe(2);
});

it('refuses release while the recorded content-hash comparison still fails and releases once the bytes are restored', function (): void {
    $previousBase = app()->basePath();
    $root = sys_get_temp_dir() . '/capell-quarantine-release-' . bin2hex(random_bytes(8));
    $name = 'vendor/evidence-held';
    $packageDir = $root . '/vendor/vendor/evidence-held';
    mkdir($root . '/vendor/composer', 0777, true);
    mkdir($packageDir . '/src', 0777, true);
    $manifest = json_decode((string) file_get_contents(__DIR__ . '/../../fixtures/manifest-v3/valid-premium-package.json'), true, flags: JSON_THROW_ON_ERROR);
    $manifest['name'] = $name;
    $manifest['providers']['runtime'] = [];
    $manifest['contributes'] = [];
    $manifest['healthChecks'] = [];
    $package = ['name' => $name, 'version' => '1.0.0', 'type' => 'library', 'install-path' => '../vendor/evidence-held', 'autoload' => ['psr-4' => ['Vendor\\EvidenceHeld\\' => 'src/']]];
    file_put_contents($root . '/vendor/composer/installed.json', json_encode(['packages' => [$package]], JSON_THROW_ON_ERROR));
    file_put_contents($packageDir . '/composer.json', json_encode(['name' => $name, 'autoload' => $package['autoload']], JSON_THROW_ON_ERROR));
    file_put_contents($packageDir . '/capell.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    file_put_contents($packageDir . '/src/Code.php', '<?php // trusted');
    app()->setBasePath($root);

    try {
        $trusted = HashExtensionContentsAction::run($packageDir);
        file_put_contents($packageDir . '/src/Code.php', '<?php // tampered');
        $tampered = HashExtensionContentsAction::run($packageDir);
        QuarantineExtensionsAction::run([$name], $name, new ExtensionQuarantineEvidenceData('content_sha256', $trusted, $tampered));

        $record = ExtensionQuarantine::query()->where('composer_name', $name)->firstOrFail();
        expect([$record->failing_comparison, $record->expected_digest, $record->actual_digest])->toBe(['content_sha256', $trusted, $tampered]);

        $command = $this->artisan('capell:extensions:release-quarantine', ['package' => $name]);
        assert($command instanceof PendingCommand);
        $command->expectsOutputToContain(__('capell-core::extensions.verification.quarantine_evidence_still_fails.message', ['package' => $name]))
            ->assertFailed()
            ->run();
        expect(ExtensionQuarantine::isQuarantined($name))->toBeTrue();

        file_put_contents($packageDir . '/src/Code.php', '<?php // trusted');
        $command = $this->artisan('capell:extensions:release-quarantine', ['package' => $name]);
        assert($command instanceof PendingCommand);
        $command->expectsOutputToContain(__('capell-core::extensions.quarantine.released', ['package' => $name]))
            ->assertSuccessful()
            ->run();
        expect(ExtensionQuarantine::isQuarantined($name))->toBeFalse();
    } finally {
        app()->setBasePath($previousBase);
        new Filesystem()->deleteDirectory($root);
    }
});
