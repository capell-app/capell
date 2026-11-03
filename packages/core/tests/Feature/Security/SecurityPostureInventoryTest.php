<?php

declare(strict_types=1);

use Capell\Core\Actions\Security\BuildSecurityPostureInventoryAction;
use Capell\Core\Contracts\Security\SecurityAdvisorySource;
use Capell\Core\Data\Security\SecurityAdvisoryData;
use Capell\Core\Enums\ExtensionAutoUpdatePolicyEnum;
use Capell\Core\Enums\ExtensionStatusEnum;
use Capell\Core\Enums\Security\SecurityWeaknessSeverity;
use Capell\Core\Models\CapellExtension;

it('reports package trust, policies, absent installer and independent advisory sources', function (): void {
    CapellExtension::query()->create(['composer_name' => 'capell-app/core', 'name' => 'Core', 'status' => ExtensionStatusEnum::Enabled, 'auto_update_policy' => ExtensionAutoUpdatePolicyEnum::Security]);
    CapellExtension::query()->create(['composer_name' => 'example/plugin', 'name' => 'Plugin', 'status' => ExtensionStatusEnum::Enabled, 'auto_update_policy' => ExtensionAutoUpdatePolicyEnum::Patch]);
    config()->offsetUnset('capell-installer');
    $first = Mockery::mock(SecurityAdvisorySource::class);
    $first->shouldReceive('advisories')->once()->andReturn([new SecurityAdvisoryData('CVE-1', 'capell-app/core', SecurityWeaknessSeverity::High, 'First')]);
    $second = Mockery::mock(SecurityAdvisorySource::class);
    $second->shouldReceive('advisories')->once()->andReturn([new SecurityAdvisoryData('CVE-2', 'example/plugin', SecurityWeaknessSeverity::Medium, 'Second')]);
    $broken = Mockery::mock(SecurityAdvisorySource::class);
    $broken->shouldReceive('advisories')->once()->andThrow(new RuntimeException('secret'));
    app()->instance('test.security.source.one', $first);
    app()->instance('test.security.source.two', $second);
    app()->instance('test.security.source.broken', $broken);
    app()->tag(['test.security.source.one', 'test.security.source.two', 'test.security.source.broken'], SecurityAdvisorySource::TAG);

    $inventory = BuildSecurityPostureInventoryAction::run();
    expect($inventory->package('capell-app/core')->trusted)->toBeTrue()
        ->and($inventory->package('capell-app/core')->autoUpdatePolicy)->toBe('security')
        ->and($inventory->package('example/plugin')->trusted)->toBeFalse()
        ->and($inventory->package('example/plugin')->autoUpdatePolicy)->toBe('patch')
        ->and($inventory->flag('installer.enabled'))->toBeNull()
        ->and(array_map(fn (SecurityAdvisoryData $advisory): string => $advisory->identifier, $inventory->advisories))->toContain('CVE-1', 'CVE-2')
        ->and($inventory->failures)->toContain('advisory source: ' . $broken::class)
        ->and($inventory->advisorySourcesRun)->toBeGreaterThanOrEqual(3);
});
