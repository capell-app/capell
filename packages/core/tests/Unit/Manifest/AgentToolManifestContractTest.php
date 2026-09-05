<?php

declare(strict_types=1);

use Capell\Core\Contracts\Agent\DefinesAgentTool;
use Capell\Core\Contracts\Extensions\ExtensionContribution;
use Capell\Core\Data\Agent\AgentToolDefinitionData;
use Capell\Core\Support\Manifest\Exceptions\InvalidManifestException;
use Capell\Core\Support\Manifest\ManifestValidator;

beforeAll(function (): void {
    if (class_exists('Vendor\\AgentTools\\AgentTool')) {
        return;
    }

    eval('namespace Vendor\\AgentTools; class AgentTool implements \\' . DefinesAgentTool::class . ' {
        public static function compatibleCapellApiVersion(): string { return "^1.0"; }
        public static function agentToolDefinition(): \\' . AgentToolDefinitionData::class . ' { throw new \\LogicException("provider-only"); }
    }');

    eval('namespace Vendor\\AgentTools; class LegacyCapability implements \\' . ExtensionContribution::class . ' {
        public static function compatibleCapellApiVersion(): string { return "^1.0"; }
    }');
});

it('requires agent capability contribution classes to implement the typed contract', function (): void {
    $manifest = capellManifestV3Array('vendor/agent-tools', ['frontend'], 'Vendor\\AgentTools');
    $manifest['contributes'] = [[
        'type' => 'agent-capability',
        'class' => 'Vendor\\AgentTools\\AgentTool',
        'context' => 'public',
        'name' => 'catalogue.lookup',
        'description' => 'Look up a published catalogue item.',
        'inputSchema' => ['type' => 'object', 'additionalProperties' => false],
        'outputSchema' => ['type' => 'object', 'additionalProperties' => false],
        'effect' => 'read',
        'binding' => ['type' => 'endpoint', 'target' => '/agent/v1/catalogue/lookup'],
    ]];

    expect(fn () => (new ManifestValidator)->validate($manifest, composerJson: [
        'name' => 'vendor/agent-tools',
        'autoload' => ['psr-4' => ['Vendor\\AgentTools\\' => 'src/']],
    ]))->not->toThrow(InvalidManifestException::class);
});

it('preserves legacy authenticated agent capability contributions', function (): void {
    $manifest = capellManifestV3Array('vendor/agent-tools', ['shared'], 'Vendor\\AgentTools');
    $manifest['contributes'] = [[
        'type' => 'agent-capability',
        'class' => 'Vendor\\AgentTools\\LegacyCapability',
    ]];

    expect(fn () => (new ManifestValidator)->validate($manifest, composerJson: [
        'name' => 'vendor/agent-tools',
        'autoload' => ['psr-4' => ['Vendor\\AgentTools\\' => 'src/']],
    ]))->not->toThrow(InvalidManifestException::class);
});

it('requires a reason for an explicit agent tools waiver', function (): void {
    $validator = new ManifestValidator;
    $manifest = capellManifestV3Array('vendor/agent-tools', ['frontend']);
    $manifest['agent_tools'] = 'none';

    expect(fn () => $validator->validate($manifest, composerJson: ['name' => 'vendor/agent-tools']))
        ->toThrow(InvalidManifestException::class, 'agent_tools waiver reason');

    $manifest['agent_tools_reason'] = 'The public surface is informational and has no actionable controls.';

    expect(fn () => $validator->validate($manifest, composerJson: ['name' => 'vendor/agent-tools']))
        ->not->toThrow(InvalidManifestException::class);
});

it('requires the typed contract when a legacy capability is explicitly public', function (): void {
    $manifest = capellManifestV3Array('vendor/agent-tools', ['frontend'], 'Vendor\\AgentTools');
    $manifest['contributes'] = [[
        'type' => 'agent-capability',
        'class' => 'Vendor\\AgentTools\\LegacyCapability',
        'context' => 'public',
    ]];

    expect(fn () => (new ManifestValidator)->validate($manifest, composerJson: [
        'name' => 'vendor/agent-tools',
        'autoload' => ['psr-4' => ['Vendor\\AgentTools\\' => 'src/']],
    ]))->toThrow(InvalidManifestException::class, 'DefinesAgentTool');
});
