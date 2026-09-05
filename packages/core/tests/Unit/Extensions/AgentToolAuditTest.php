<?php

declare(strict_types=1);

use Capell\Core\Actions\Extensions\AuditExtensionContractsAction;
use Capell\Core\Contracts\Extensions\RegistersExtensionRoute;
use Capell\Core\Enums\ExtensionContributionType;

beforeAll(function (): void {
    if (class_exists('Vendor\\Interactive\\PublicRoutes')) {
        return;
    }

    eval('namespace Vendor\\Interactive; class PublicRoutes implements \\' . RegistersExtensionRoute::class . ' {
        public static function compatibleCapellApiVersion(): string { return "^1.0"; }
    }');
});

function agentAuditManifest(array $overrides = []): array
{
    return array_replace_recursive(capellManifestV3Array('vendor/interactive', ['frontend'], 'Vendor\\Interactive'), [
        'contributes' => [[
            'type' => ExtensionContributionType::Route->value,
            'class' => 'Vendor\\Interactive\\PublicRoutes',
            'surface' => 'frontend',
        ]],
    ], $overrides);
}

function agentAuditPackage(array $manifest): string
{
    $directory = sys_get_temp_dir() . '/capell-agent-audit-' . bin2hex(random_bytes(6));
    mkdir($directory, recursive: true);
    file_put_contents($directory . '/capell.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    file_put_contents($directory . '/composer.json', json_encode([
        'name' => 'vendor/interactive',
        'autoload' => ['psr-4' => ['Vendor\\Interactive\\' => 'src/']],
    ], JSON_THROW_ON_ERROR));

    return $directory;
}

it('warns when a public interactive surface has no tool declaration or waiver', function (): void {
    $results = AuditExtensionContractsAction::run(agentAuditPackage(agentAuditManifest()));
    $finding = collect($results)->firstOrFail('message', 'Interactive surface has no declared agent tool or agent_tools none waiver.');

    expect($finding)->not->toBeNull()
        ->and($finding['package'])->toBe('vendor/interactive')
        ->and($finding['severity'])->toBe('warning')
        ->and($finding['context'])->toMatchArray(['interactiveSurface' => true]);
});

it('reports a waiver and supports the future strict error severity', function (): void {
    $manifest = agentAuditManifest(['agent_tools' => 'none', 'agent_tools_reason' => 'No actionable controls.']);
    $waiverResults = AuditExtensionContractsAction::run(agentAuditPackage($manifest));
    $waiverFinding = collect($waiverResults)->firstOrFail('message', 'Interactive surface declares an explicit agent_tools none waiver.');
    expect($waiverFinding)->not->toBeNull()
        ->and($waiverFinding['severity'])->toBe('info')
        ->and($waiverFinding['context'])->toMatchArray(['reason' => 'No actionable controls.']);

    config(['capell.agent.audit_severity' => 'error']);
    $strictResults = AuditExtensionContractsAction::run(agentAuditPackage(agentAuditManifest()));
    $strictFinding = collect($strictResults)->firstOrFail('message', 'Interactive surface has no declared agent tool or agent_tools none waiver.');

    expect($strictFinding)->not->toBeNull()
        ->and($strictFinding['severity'])->toBe('error');
});

it('accepts a declarative tool on an interactive surface', function (): void {
    $manifest = agentAuditManifest([
        'agent_tools' => [[
            'name' => 'interactive.lookup',
            'description' => 'Look up an interactive item.',
            'inputSchema' => ['type' => 'object', 'additionalProperties' => false],
            'outputSchema' => ['type' => 'object', 'additionalProperties' => false],
            'effect' => 'read',
            'binding' => ['type' => 'search', 'target' => 'interactive.lookup'],
        ]],
    ]);

    $results = AuditExtensionContractsAction::run(agentAuditPackage($manifest));

    expect(collect($results)->firstWhere('message', 'Interactive surface has no declared agent tool or agent_tools none waiver.'))
        ->toBeNull();
});
