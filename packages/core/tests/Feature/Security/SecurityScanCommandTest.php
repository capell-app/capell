<?php

declare(strict_types=1);

use Capell\Core\Actions\Security\BuildSecurityPostureInventoryAction;
use Capell\Core\Actions\Security\RunSecurityWeaknessScanAction;
use Capell\Core\Contracts\Security\SecurityWeaknessRemediation;
use Capell\Core\Data\Security\SecurityPostureInventoryData;
use Capell\Core\Data\Security\SecurityRemediationOutcomeData;
use Capell\Core\Data\Security\SecurityScanResultData;
use Capell\Core\Enums\Security\SecurityFindingSource;
use Capell\Core\Enums\Security\SecurityWeaknessSeverity;
use Capell\Core\Models\SecurityRemediationApproval;
use Capell\Core\Models\SecurityRemediationRun;
use Capell\Core\Models\SecurityWeaknessFinding;
use Capell\Core\Support\Security\Checks\DebugEnabledCheck;
use Capell\Core\Support\Security\NullSecurityWeaknessAnalyser;
use Capell\Core\Support\Security\Remediations\DebugEnabledRemediation;
use Capell\Core\Support\Security\Remediations\EnvironmentRemediation;
use Capell\Core\Support\Security\SecurityOutputSanitiser;
use Capell\Core\Support\Security\SecurityWeaknessRegistry;
use Capell\Core\Tests\Support\Security\FakeConfigClearCommand;
use Capell\Core\Tests\Support\Security\TestRemediation;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Output\BufferedOutput;

function fakeCommandScan(bool $includeAnalyser, string $source = 'system:console'): void
{
    RunSecurityWeaknessScanAction::mock()->shouldReceive('handle')->once()->with($includeAnalyser, $source)
        ->andReturn(new SecurityScanResultData(CarbonImmutable::now()->subMinute(), 1, 0, null, 0, 0, 0, 1, SecurityWeaknessSeverity::High));
}

it('lists active findings in a table and fails for open critical findings', function (): void {
    SecurityWeaknessFinding::factory()->create(['severity' => SecurityWeaknessSeverity::Critical, 'title' => 'Critical example']);
    fakeCommandScan(true);
    // One table row holds both values, and expectsOutputToContain() consumes only one expectation per output line.
    $exit = Artisan::call('capell:security:scan');
    $output = Artisan::output();
    expect($exit)->toBe(1)->and($output)->toContain('Critical example')->toContain('app.debug_enabled');
});

it('outputs valid json and honours no-analyser', function (): void {
    SecurityWeaknessFinding::factory()->create(['title' => 'Example finding']);
    fakeCommandScan(false);
    $exit = Artisan::call('capell:security:scan', ['--json' => true, '--no-analyser' => true]);
    $output = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    expect($exit)->toBe(0)->and($output)->toHaveKeys(['scan', 'findings', 'remediations'])
        ->and($output['scan']['checksRun'])->toBe(1)
        ->and($output['findings'][0]['title'])->toBe('Example finding')
        ->and($output['remediations'])->toBeNull();
});

it('applies approved fixes and reports their runs', function (): void {
    $registry = new SecurityWeaknessRegistry;
    $registry->registerRemediation(new TestRemediation);

    app()->instance(SecurityWeaknessRegistry::class, $registry);
    SecurityWeaknessFinding::factory()->create();
    SecurityRemediationApproval::factory()->create(['remediation_class' => TestRemediation::class]);
    fakeCommandScan(true);
    $exit = Artisan::call('capell:security:scan', ['--fix-approved' => true]);
    expect($exit)->toBe(0)->and(Artisan::output())->toContain('app.debug_enabled: applied');
    expect(SecurityRemediationRun::query()->count())->toBe(1);
});

it('reports dry runs without writing a remediation', function (): void {
    $registry = new SecurityWeaknessRegistry;
    $registry->registerRemediation(new TestRemediation);

    app()->instance(SecurityWeaknessRegistry::class, $registry);
    $finding = SecurityWeaknessFinding::factory()->create();
    SecurityRemediationApproval::factory()->create(['remediation_class' => TestRemediation::class]);
    fakeCommandScan(true);
    $exit = Artisan::call('capell:security:scan', ['--fix-approved' => true, '--dry-run' => true]);
    expect($exit)->toBe(0)->and(Artisan::output())->toContain('dry-run');
    expect(SecurityRemediationRun::query()->count())->toBe(0)->and($finding->refresh()->status->value)->toBe('open');
});

it('returns two for incomplete scans and honours the only option', function (): void {
    $registry = new SecurityWeaknessRegistry;
    $registry->registerRemediation(new TestRemediation);

    app()->instance(SecurityWeaknessRegistry::class, $registry);
    $finding = SecurityWeaknessFinding::factory()->create();
    SecurityRemediationApproval::factory()->create(['remediation_class' => TestRemediation::class]);
    RunSecurityWeaknessScanAction::mock()->shouldReceive('handle')->once()->andReturn(new SecurityScanResultData(CarbonImmutable::now()->subMinute(), 1, 0, null, 0, 0, 0, 1, SecurityWeaknessSeverity::High, ['check: app.debug_enabled']));
    expect(Artisan::call('capell:security:scan', ['--fix-approved' => true, '--only' => 'app.debug_enabled']))->toBe(2)
        ->and($finding->fresh()->status->value)->toBe('open')
        ->and(SecurityRemediationRun::query()->count())->toBe(0);
});

it('uses the scheduler actor and skips analyser advice in scheduled fixes', function (): void {
    $registry = new SecurityWeaknessRegistry;
    $registry->registerRemediation(new TestRemediation);

    app()->instance(SecurityWeaknessRegistry::class, $registry);
    $builtin = SecurityWeaknessFinding::factory()->create();
    $advice = SecurityWeaknessFinding::factory()->create(['source' => SecurityFindingSource::Analyser]);
    SecurityRemediationApproval::factory()->create(['remediation_class' => TestRemediation::class]);
    fakeCommandScan(true, 'system:scheduler');
    expect(Artisan::call('capell:security:scan', ['--fix-approved' => true, '--actor' => 'scheduler']))->toBe(0)
        ->and($builtin->fresh()->status->value)->toBe('remediated')->and($advice->fresh()->status->value)->toBe('open')
        ->and(SecurityRemediationRun::query()->sole()->actor)->toBe('system:scheduler');
});

it('returns two when an approved fix fails', function (): void {
    $fix = Mockery::mock(SecurityWeaknessRemediation::class);
    $fix->shouldReceive('weaknessId')->andReturn('app.debug_enabled');
    $fix->shouldReceive('apply')->once()->andThrow(new RuntimeException('failure'));
    $registry = new SecurityWeaknessRegistry;
    $registry->registerRemediation($fix);

    app()->instance(SecurityWeaknessRegistry::class, $registry);
    SecurityWeaknessFinding::factory()->create();
    SecurityRemediationApproval::factory()->create(['remediation_class' => $fix::class]);
    fakeCommandScan(true);
    expect(Artisan::call('capell:security:scan', ['--fix-approved' => true]))->toBe(2)
        ->and(SecurityRemediationRun::query()->sole()->actor)->toBe('system:console');
});

it('limits requested remediation to the selected weakness', function (): void {
    $registry = new SecurityWeaknessRegistry;
    $registry->registerRemediation(new TestRemediation);

    app()->instance(SecurityWeaknessRegistry::class, $registry);
    $finding = SecurityWeaknessFinding::factory()->create();
    SecurityRemediationApproval::factory()->create(['remediation_class' => TestRemediation::class]);
    fakeCommandScan(true);
    expect(Artisan::call('capell:security:scan', ['--fix-approved' => true, '--only' => 'other.issue']))->toBe(0)
        ->and($finding->fresh()->status->value)->toBe('open')->and(SecurityRemediationRun::query()->count())->toBe(0);
});

it('prints sanitised stored findings and remediation messages in both formats', function (bool $json): void {
    $fix = Mockery::mock(SecurityWeaknessRemediation::class);
    $fix->shouldReceive('weaknessId')->andReturn('app.debug_enabled');
    $fix->shouldReceive('apply')->once()->andReturn(new SecurityRemediationOutcomeData(true, 'Bearer distinctiveToken123'));
    $registry = new SecurityWeaknessRegistry;
    $registry->registerRemediation($fix);

    app()->instance(SecurityWeaknessRegistry::class, $registry);
    SecurityWeaknessFinding::factory()->create(['title' => (new SecurityOutputSanitiser)->text('APP_KEY=base64:distinctiveSecret123', 200)]);
    SecurityRemediationApproval::factory()->create(['remediation_class' => $fix::class]);
    fakeCommandScan(true);
    Artisan::call('capell:security:scan', ['--fix-approved' => true, '--json' => $json]);
    expect(Artisan::output())->not->toContain('distinctiveToken123')->not->toContain('distinctiveSecret123');
})->with([false, true]);

it('reports a failed cache refresh and retries it on the next approved scan', function (): void {
    $directory = sys_get_temp_dir() . '/capell-cache-remediation-' . uniqid();
    File::ensureDirectoryExists($directory);
    file_put_contents($directory . '/.env', "APP_DEBUG=true\n");
    $base = app()->basePath();
    $storage = app()->storagePath();
    app()->setBasePath($directory);
    app()->useStoragePath($directory . '/storage');
    // Laravel answers configurationIsCached() from this flag before it looks at the cache file.
    app()->instance('config_loaded_from_cache', true);
    try {
        BuildSecurityPostureInventoryAction::mock()->shouldReceive('handle')->andReturn(
            new SecurityPostureInventoryData(null, [], ['app.debug' => true], [], CarbonImmutable::now()),
        );
        $registry = new SecurityWeaknessRegistry;
        $registry->registerCheck(new DebugEnabledCheck);
        $registry->registerRemediation(new DebugEnabledRemediation(new EnvironmentRemediation));
        app()->instance(SecurityWeaknessRegistry::class, $registry);
        new RunSecurityWeaknessScanAction($registry, new NullSecurityWeaknessAnalyser)->handle(false);
        SecurityRemediationApproval::factory()->create();
        FakeConfigClearCommand::$exitCodes = [1, 0];
        FakeConfigClearCommand::$calls = [];
        Artisan::registerCommand(new FakeConfigClearCommand);
        $kernel = resolve(Kernel::class);
        $output = new BufferedOutput;
        $firstExit = $kernel->call('capell:security:scan', ['--fix-approved' => true, '--no-analyser' => true, '--json' => true], $output);
        $firstOutput = $output->fetch();
        expect($firstExit)->toBe(2);
        $message = __('capell-core::security.remediation.cache_refresh_required');
        expect($firstOutput)->toContain($message)
            ->and(SecurityRemediationRun::query()->sole()->outcome->value)->toBe('failed')
            ->and(SecurityWeaknessFinding::query()->sole()->status->value)->toBe('open')
            ->and(file_get_contents($directory . '/.env'))->toBe("APP_DEBUG=false\n");
        $output = new BufferedOutput;
        expect($kernel->call('capell:security:scan', ['--fix-approved' => true, '--no-analyser' => true, '--json' => true], $output))->toBe(0)
            ->and(FakeConfigClearCommand::$calls)->toBe([1, 0])
            ->and(SecurityRemediationRun::query()->latest('id')->firstOrFail()->outcome->value)->toBe('applied')
            ->and(SecurityWeaknessFinding::query()->sole()->status->value)->toBe('remediated')
            ->and(file_get_contents($directory . '/.env'))->toBe("APP_DEBUG=false\n");
    } finally {
        app()->setBasePath($base);
        app()->useStoragePath($storage);
        app()->instance('config_loaded_from_cache', false);
        File::deleteDirectory($directory);
    }
});
