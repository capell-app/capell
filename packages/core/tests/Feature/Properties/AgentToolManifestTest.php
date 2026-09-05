<?php

declare(strict_types=1);

use Capell\Core\Actions\Properties\BuildAgentToolManifestAction;
use Capell\Core\Data\Agent\AgentToolBindingData;
use Capell\Core\Data\Agent\AgentToolDefinitionData;
use Capell\Core\Enums\Agent\AgentToolBindingType;
use Capell\Core\Enums\Agent\AgentToolEffect;
use Capell\Core\Support\Agent\AgentToolDefinitionNormalizer;
use Capell\Core\Support\Agent\AgentToolRegistry;

it('only exposes read tools with Core descriptions and versioned metadata', function (): void {
    $manifest = BuildAgentToolManifestAction::run(true);

    expect($manifest['capellAgentSchema'])->toBe(1)
        ->and(array_column($manifest['tools'], 'name'))->toBe(['page.get', 'site.pages.query', 'site.taxonomies.browse', 'site.navigation', 'site.search']);
    foreach ($manifest['tools'] as $tool) {
        expect($tool['effect'])->toBe('read')
            ->and($tool['description'])->not->toContain('capell-core::')
            ->and($tool['inputSchema']['additionalProperties'])->toBeFalse();
    }
});

it('omits remote tools when the read API is disabled', function (): void {
    expect(array_column(BuildAgentToolManifestAction::run(true, false)['tools'], 'name'))->toBe(['page.get'])
        ->and(BuildAgentToolManifestAction::run(false, false)['tools'])->toBe([]);
});

it('publishes declared tool contracts without package ownership metadata', function (): void {
    $registry = new AgentToolRegistry;
    $registry->register(new AgentToolDefinitionData(
        name: 'contact.send',
        description: 'Send the contact form',
        inputSchema: ['type' => 'object', 'properties' => ['message' => ['type' => 'string']]],
        outputSchema: ['type' => 'object'],
        effect: AgentToolEffect::Write,
        binding: new AgentToolBindingData(AgentToolBindingType::Form, 'contact-form'),
        ownerPackage: 'internal/vendor-package',
    ));

    $manifest = BuildAgentToolManifestAction::run(false, false, false, $registry);
    expect($manifest['tools'])->toHaveCount(1)
        ->and($manifest['tools'][0]['binding'])->toBe(['type' => 'form', 'target' => 'contact-form'])
        ->and($manifest['tools'][0]['effect'])->toBe('write')
        ->and(json_encode($manifest, JSON_THROW_ON_ERROR))->not->toContain('ownerPackage', 'internal/vendor-package');
});

it('rejects declarations that replace a built-in tool with different behaviour', function (): void {
    $registry = new AgentToolRegistry;
    $registry->register(new AgentToolDefinitionData(
        name: 'page.get',
        description: 'Replace the built-in',
        inputSchema: ['type' => 'object'],
        outputSchema: ['type' => 'object'],
        effect: AgentToolEffect::Write,
        binding: new AgentToolBindingData(AgentToolBindingType::Form, 'contact-form'),
    ));

    expect(fn (): mixed => BuildAgentToolManifestAction::run(true, false, false, $registry))
        ->toThrow(InvalidArgumentException::class);
});

it('deduplicates an equivalent built-in declaration decoded from a manifest', function (): void {
    $manifest = BuildAgentToolManifestAction::run(false, true, true);
    $search = collect($manifest['tools'])->firstWhere('name', 'site.search');
    $decoded = json_decode(json_encode($search, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    $registry = new AgentToolRegistry;
    $registry->register(resolve(AgentToolDefinitionNormalizer::class)->normalize($decoded));

    expect(BuildAgentToolManifestAction::run(false, true, true, $registry)['tools'])->toHaveCount(4);
});
