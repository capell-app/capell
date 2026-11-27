<?php

declare(strict_types=1);

use Capell\Core\Actions\Diagnostics\BuildAgentDoctorReportAction;
use Capell\Core\Data\PageTypeData;
use Capell\Core\Enums\ExtensionContributionType;
use Capell\Core\Enums\PackageCapability;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Page;
use Capell\Core\Support\Extensions\ExtensionContributionReceiptRegistry;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Capell\Core\ThemeStudio\Data\ThemeDefinitionData;
use Capell\Core\ThemeStudio\Theme\ThemeRegistry;
use Illuminate\Support\Facades\Artisan;

/** @return array<string, mixed> */
function agentDoctorReport(): array
{
    expect(Artisan::call('capell:agent:doctor', ['--json' => true]))->toBe(0);

    return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
}

it('reports what an agent can build with as one JSON document', function (): void {
    resolve(ThemeRegistry::class)->register(new ThemeDefinitionData(
        key: 'doctor-probe-theme',
        name: 'Doctor probe',
        description: 'A theme the doctor must list.',
        package: 'vendor/doctor-probe-theme',
        previewImage: '/preview.svg',
        tags: [],
        bestFit: [],
        presets: [],
        extends: 'default',
    ));
    CapellCore::registerPageType(new PageTypeData('doctor-probe-page', Page::class, 'Doctor probe page'));
    resolve(ExtensionContributionReceiptRegistry::class)->recordContribution(
        ExtensionContributionType::BuilderBlock,
        'doctor-probe-block',
        'Vendor\\Probe\\Block',
        'Vendor\\Probe\\Provider',
        'admin',
    );

    $report = agentDoctorReport();

    expect($report['capell-agent-doctor'])->toBe(1)
        ->and(array_column($report['themes'], 'key'))->toContain('doctor-probe-theme')
        ->and(collect($report['themes'])->firstWhere('key', 'doctor-probe-theme'))->toMatchArray(['extends' => 'default', 'installed' => false])
        ->and(array_column($report['pageTypes'], 'name'))->toContain('doctor-probe-page')
        ->and(array_column($report['widgets'], 'key'))->toContain('doctor-probe-block')
        ->and(array_column($report['makers'], 'key'))->toContain('core.action')
        ->and(collect($report['makers'])->firstWhere('key', 'core.action')['command'])->toBe('php artisan capell:make core.action --name=<Name> --dry-run')
        ->and(array_column($report['packages'], 'name'))->toContain('capell-app/core')
        ->and(array_column($report['commands'], 'command'))->toContain('php artisan capell:make');
});

it('tells an agent how to get MCP when Agent Bridge is missing', function (): void {
    $mcp = agentDoctorReport()['mcp'];

    expect($mcp['agentBridge'])->toBeFalse()
        ->and($mcp['status'])->toBe('missing')
        ->and($mcp['nextStep'])->toContain('Agent Bridge extension');
});

it('finds Agent Bridge by the capability its manifest declares, not by its package name', function (): void {
    $data = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/manifest-v3/valid-premium-package.json'), true, flags: JSON_THROW_ON_ERROR);
    $data['name'] = 'vendor/any-name-at-all';
    $data['capabilities'] = [PackageCapability::AgentBridge->value];
    resolve(CapellPackageRegistry::class)->register(CapellManifestData::fromArray($data));

    expect(agentDoctorReport()['mcp'])->toMatchArray(['agentBridge' => true]);
});

it('asks for laravel/mcp, not another Agent Bridge install, when Agent Bridge is already installed into Capell', function (): void {
    expect(BuildAgentDoctorReportAction::mcpStatus(laravelMcp: false, agentBridge: true))
        ->toMatchArray(['agentBridge' => true, 'status' => 'missing-laravel-mcp', 'nextStep' => 'composer require laravel/mcp'])
        ->and(BuildAgentDoctorReportAction::mcpStatus(laravelMcp: true, agentBridge: true)['status'])->toBe('ready');
});

it('prints a readable summary without the JSON flag', function (): void {
    artisanCommand('capell:agent:doctor')
        ->expectsOutputToContain('Makers: ')
        ->expectsOutputToContain('MCP: missing')
        ->expectsOutputToContain('Run with --json for the same report as JSON.')
        ->assertExitCode(0);
});
