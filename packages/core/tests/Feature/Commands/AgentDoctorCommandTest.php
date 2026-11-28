<?php

declare(strict_types=1);

use Capell\Core\Actions\Diagnostics\BuildAgentDoctorReportAction;
use Capell\Core\Data\PageTypeData;
use Capell\Core\Enums\ExtensionContributionType;
use Capell\Core\Enums\PackageCapability;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Page;
use Capell\Core\Models\Theme;
use Capell\Core\Support\Extensions\ExtensionContributionReceiptRegistry;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Capell\Core\ThemeStudio\Data\ThemeDefinitionData;
use Capell\Core\ThemeStudio\Theme\ThemeRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

function registerAgentDoctorProbeTheme(): void
{
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
}

/** Make every database read fail the way an unreachable server does, with secrets in the driver message. */
function failAgentDoctorDatabaseReads(): void
{
    DB::connection()->beforeExecuting(function (string $query, array $bindings): never {
        $driverError = new class('SQLSTATE[HY000] [2002] Connection refused: db-secret.internal:3306 user=capell_secret_user password=hunter2-secret') extends PDOException
        {
            /** @var string PDO reports the SQLSTATE as the exception code. */
            protected $code = 'HY000';
        };

        throw new QueryException('mysql', $query, $bindings, $driverError);
    });
}

/** @return array<string, mixed> */
function agentDoctorReport(): array
{
    expect(Artisan::call('capell:agent:doctor', ['--json' => true]))->toBe(0);

    return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
}

it('reports what an agent can build with as one JSON document', function (): void {
    registerAgentDoctorProbeTheme();
    CapellCore::registerPageType(new PageTypeData('doctor-probe-page', Page::class, 'Doctor probe page'));
    resolve(ExtensionContributionReceiptRegistry::class)->recordContribution(
        ExtensionContributionType::BuilderBlock,
        'doctor-probe-block',
        'Vendor\\Probe\\Block',
        'Vendor\\Probe\\Provider',
        'admin',
    );

    $report = agentDoctorReport();

    expect($report['capell-agent-doctor'])->toBe(2)
        ->and(array_column($report['themes'], 'key'))->toContain('doctor-probe-theme')
        ->and(collect($report['themes'])->firstWhere('key', 'doctor-probe-theme'))->toMatchArray(['extends' => 'default', 'installed' => false])
        ->and(array_column($report['pageTypes'], 'name'))->toContain('doctor-probe-page')
        ->and(array_column($report['widgets'], 'key'))->toContain('doctor-probe-block')
        ->and(array_column($report['makers'], 'key'))->toContain('core.action')
        ->and(collect($report['makers'])->firstWhere('key', 'core.action')['command'])->toBe('php artisan capell:make core.action --name=<Name> --dry-run')
        ->and(array_column($report['packages'], 'name'))->toContain('capell-app/core')
        ->and(array_column($report['commands'], 'command'))->toContain('php artisan capell:make')
        ->and($report['diagnostics'])->toBe([]);
});

it('reports a theme with a theme record as installed', function (): void {
    registerAgentDoctorProbeTheme();
    Theme::factory()->create(['key' => 'doctor-probe-theme']);

    expect(collect(agentDoctorReport()['themes'])->firstWhere('key', 'doctor-probe-theme')['installed'])->toBeTrue();
});

it('reports theme install state as unknown, without the database error text, when the theme records cannot be read', function (): void {
    registerAgentDoctorProbeTheme();
    Theme::factory()->create(['key' => 'doctor-probe-theme']);
    failAgentDoctorDatabaseReads();

    $json = Artisan::call('capell:agent:doctor', ['--json' => true]) === 0 ? Artisan::output() : '';
    $report = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

    expect(collect($report['themes'])->firstWhere('key', 'doctor-probe-theme')['installed'])->toBeNull()
        ->and($report['diagnostics'])->toHaveCount(1)
        ->and($report['diagnostics'][0]['id'])->toBe('themes.records-unreadable')
        ->and($report['diagnostics'][0]['message'])->toContain('SQLSTATE HY000');

    Artisan::call('capell:agent:doctor');
    $table = Artisan::output();

    expect($table)->toMatch('/doctor-probe-theme\b.*\bunknown\b/')
        ->toContain('Theme install state is unknown');

    foreach ([$json, $table] as $output) {
        expect($output)->not->toContain('db-secret.internal')
            ->not->toContain('capell_secret_user')
            ->not->toContain('hunter2-secret')
            ->not->toContain('Connection refused');
    }
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
