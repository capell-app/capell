<?php

declare(strict_types=1);

use Capell\Core\Actions\Agent\DiscoverAgentToolDefinitionsAction;
use Capell\Core\Contracts\Agent\DefinesAgentTool;
use Capell\Core\Data\Agent\AgentToolBindingData;
use Capell\Core\Data\Agent\AgentToolDefinitionData;
use Capell\Core\Enums\Agent\AgentToolBindingType;
use Capell\Core\Enums\Agent\AgentToolEffect;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Agent\AgentToolRegistry;
use Capell\Core\Support\Manifest\CapellManifestData;

beforeAll(function (): void {
    if (class_exists('Vendor\\AgentDiscovery\\PublicTool')) {
        return;
    }

    eval('namespace Vendor\\AgentDiscovery; class PublicTool implements \\' . DefinesAgentTool::class . ' {
        public static function compatibleCapellApiVersion(): string { return "^1.0"; }
        public static function agentToolDefinition(): \\' . AgentToolDefinitionData::class . ' {
            return new \\' . AgentToolDefinitionData::class . '(
                name: "page.get",
                description: "Read a published page.",
                inputSchema: ["type" => "object", "additionalProperties" => false],
                outputSchema: ["type" => "object", "additionalProperties" => false],
                effect: \\' . AgentToolEffect::class . '::Read,
                binding: new \\' . AgentToolBindingData::class . '(\\' . AgentToolBindingType::class . '::Inline, "page"),
            );
        }
    }');
});

afterEach(function (): void {
    CapellCore::clearPackages();
});

function discoverAgentManifest(string $name, array $surfaces, array $contributes): CapellManifestData
{
    return CapellManifestData::fromArray(capellManifestV3Array(
        name: $name,
        surfaces: $surfaces,
        namespace: 'Vendor\\AgentDiscovery',
        overrides: ['contributes' => $contributes],
    ));
}

function discoverAgentContribution(string $class, array $metadata = []): array
{
    return [
        'type' => 'agent-capability',
        'class' => $class,
        ...$metadata,
    ];
}

it('discovers enabled public typed declarations and records their owner', function (): void {
    $manifest = discoverAgentManifest('vendor/public-agent', ['frontend'], [
        discoverAgentContribution('Vendor\\AgentDiscovery\\PublicTool', [
            'context' => 'public',
            'providerBucket' => 'runtime',
        ]),
    ]);
    CapellCore::registerManifestPackage($manifest);
    CapellCore::forcePackageInstalled($manifest->name);

    $registry = DiscoverAgentToolDefinitionsAction::run([$manifest->name => $manifest]);

    expect($registry->definitions())->toHaveKey('page.get')
        ->and($registry->definition('page.get')->ownerPackage)->toBe($manifest->name)
        ->and($registry->definition('page.get')->toPublicArray())->not->toHaveKey('ownerPackage');
});

it('excludes disabled packages and admin capabilities from discovery', function (): void {
    $enabled = discoverAgentManifest('vendor/enabled-agent', ['frontend'], [
        discoverAgentContribution('Vendor\\AgentDiscovery\\PublicTool', ['context' => 'public']),
    ]);
    $disabled = discoverAgentManifest('vendor/disabled-agent', ['frontend'], [
        discoverAgentContribution('Vendor\\AgentDiscovery\\PublicTool', ['context' => 'public', 'key' => 'disabled']),
    ]);
    $admin = discoverAgentManifest('vendor/admin-agent', ['frontend'], [
        discoverAgentContribution('Vendor\\AgentDiscovery\\PublicTool', [
            'context' => 'public',
            'providerBucket' => 'admin',
        ]),
    ]);

    foreach ([$enabled, $disabled, $admin] as $manifest) {
        CapellCore::registerManifestPackage($manifest);
    }

    CapellCore::forcePackageInstalled($enabled->name);
    CapellCore::forcePackageInstalled($disabled->name, false);
    CapellCore::forcePackageInstalled($admin->name);

    $registry = DiscoverAgentToolDefinitionsAction::run([
        $enabled->name => $enabled,
        $disabled->name => $disabled,
        $admin->name => $admin,
    ], new AgentToolRegistry);

    expect($registry->definitions())->toHaveCount(1)
        ->and($registry->definition('page.get')->ownerPackage)->toBe($enabled->name);
});

it('round trips and discovers data-only manifest tool declarations', function (): void {
    $declaration = [
        'name' => 'site.navigation',
        'description' => 'Read public navigation.',
        'inputSchema' => ['type' => 'object', 'additionalProperties' => false],
        'outputSchema' => ['type' => 'array', 'items' => ['type' => 'object']],
        'effect' => 'read',
        'binding' => ['type' => 'endpoint', 'target' => '/agent/v1/navigation'],
    ];
    $manifest = discoverAgentManifest('vendor/manifest-agent', ['admin', 'frontend'], []);
    $manifest = CapellManifestData::fromArray($manifest->toArray() + ['agent_tools' => [$declaration]]);

    expect($manifest->agentTools)->toBe([$declaration])
        ->and($manifest->toArray()['agent_tools'])->toBe([$declaration]);

    CapellCore::registerManifestPackage($manifest);
    CapellCore::forcePackageInstalled($manifest->name);

    $registry = DiscoverAgentToolDefinitionsAction::run([$manifest->name => $manifest]);

    expect($registry->definition('site.navigation')->ownerPackage)->toBe($manifest->name);
});
