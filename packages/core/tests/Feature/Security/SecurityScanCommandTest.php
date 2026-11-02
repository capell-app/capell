<?php

declare(strict_types=1);

use Capell\Core\Actions\Security\RunSecurityWeaknessScanAction;
use Capell\Core\Data\Security\SecurityScanResultData;
use Capell\Core\Enums\Security\SecurityWeaknessSeverity;
use Capell\Core\Models\SecurityRemediationApproval;
use Capell\Core\Models\SecurityRemediationRun;
use Capell\Core\Models\SecurityWeaknessFinding;
use Capell\Core\Support\Security\SecurityWeaknessRegistry;
use Capell\Core\Tests\Support\Security\TestRemediation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;

function fakeCommandScan(bool $includeAnalyser): void
{
    RunSecurityWeaknessScanAction::mock()->shouldReceive('handle')->once()->with($includeAnalyser)
        ->andReturn(new SecurityScanResultData(CarbonImmutable::now(), 1, 0, null, 0, 0, 0, 1, SecurityWeaknessSeverity::High));
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
    SecurityRemediationApproval::factory()->create();
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
    SecurityRemediationApproval::factory()->create();
    fakeCommandScan(true);
    $exit = Artisan::call('capell:security:scan', ['--fix-approved' => true, '--dry-run' => true]);
    expect($exit)->toBe(0)->and(Artisan::output())->toContain('dry-run');
    expect(SecurityRemediationRun::query()->count())->toBe(0)->and($finding->refresh()->status->value)->toBe('open');
});
