<?php

declare(strict_types=1);

use Capell\Core\Data\Agent\AgentToolDefinitionData;
use Capell\Core\Enums\Agent\AgentToolBindingType;
use Capell\Core\Enums\Agent\AgentToolEffect;
use Capell\Core\Support\Agent\AgentToolDefinitionNormalizer;
use Capell\Core\Support\Agent\AgentToolRegistry;

function agentToolDefinitionFixture(array $overrides = []): array
{
    return array_replace_recursive([
        'name' => 'catalogue.lookup',
        'description' => 'Look up a published catalogue item.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => ['sku' => ['type' => 'string']],
            'required' => ['sku'],
            'additionalProperties' => false,
        ],
        'outputSchema' => [
            'type' => 'object',
            'properties' => ['title' => ['type' => 'string']],
            'additionalProperties' => false,
        ],
        'effect' => 'read',
        'binding' => ['type' => 'endpoint', 'target' => '/agent/v1/catalogue/lookup'],
    ], $overrides);
}

it('normalises a declarative tool definition into a typed contract', function (): void {
    $definition = (new AgentToolDefinitionNormalizer)->normalize(agentToolDefinitionFixture(), 'vendor/catalogue');

    expect($definition)->toBeInstanceOf(AgentToolDefinitionData::class)
        ->and($definition->name)->toBe('catalogue.lookup')
        ->and($definition->effect)->toBe(AgentToolEffect::Read)
        ->and($definition->binding->type)->toBe(AgentToolBindingType::Endpoint)
        ->and($definition->ownerPackage)->toBe('vendor/catalogue')
        ->and($definition->toPublicArray())->not->toHaveKey('ownerPackage');
});

it('resolves trusted translation keys while rejecting executable declaration fields', function (): void {
    $normalizer = new AgentToolDefinitionNormalizer;

    expect($normalizer->normalize(agentToolDefinitionFixture([
        'description' => null,
        'descriptionKey' => 'capell-core::agent.tools.site_search',
    ]))->description)->toContain('Search published site content')
        ->and(fn (): AgentToolDefinitionData => $normalizer->normalize(agentToolDefinitionFixture(['handler' => 'App\\DangerousHandler'])))
        ->toThrow(InvalidArgumentException::class, 'unsupported fields')
        ->and(fn (): AgentToolDefinitionData => $normalizer->normalize(agentToolDefinitionFixture([
            'inputSchema' => ['type' => 'object', 'x-handler' => 'exec'],
        ])))->toThrow(InvalidArgumentException::class, 'unsupported or executable');

    expect(fn (): AgentToolDefinitionData => $normalizer->normalize(agentToolDefinitionFixture([
        'binding' => [
            'type' => 'endpoint',
            'target' => '/agent/v1/catalogue/lookup',
            'handler' => 'App\\DangerousHandler',
        ],
    ])))->toThrow(InvalidArgumentException::class, 'unsupported or executable');

    expect($normalizer->normalize(agentToolDefinitionFixture([
        'inputSchema' => [
            'type' => 'object',
            'properties' => [],
            'additionalProperties' => [],
            'minProperties' => 0,
            'maxProperties' => 10,
        ],
    ])))->toBeInstanceOf(AgentToolDefinitionData::class)
        ->and(fn (): AgentToolDefinitionData => $normalizer->normalize(agentToolDefinitionFixture([
            'inputSchema' => [
                'type' => 'object',
                'additionalProperties' => ['type' => 'object', 'x-handler' => 'exec'],
            ],
        ])))->toThrow(InvalidArgumentException::class, 'unsupported or executable');
});

it('deduplicates identical registrations and rejects semantic collisions', function (): void {
    $registry = new AgentToolRegistry;
    $normalizer = new AgentToolDefinitionNormalizer;
    $definition = $normalizer->normalize(agentToolDefinitionFixture());

    expect($registry->register($definition))->toBe($registry)
        ->and($registry->register($definition))->toBe($registry)
        ->and($registry->definitions())->toHaveCount(1)
        ->and(fn (): AgentToolRegistry => $registry->register($normalizer->normalize(agentToolDefinitionFixture([
            'description' => 'A different instruction.',
        ]))))->toThrow(InvalidArgumentException::class, 'different semantics');
});

it('constrains each binding target to its declarative public shape', function (): void {
    $normalizer = new AgentToolDefinitionNormalizer;

    expect(fn (): AgentToolDefinitionData => $normalizer->normalize(agentToolDefinitionFixture([
        'binding' => ['type' => 'inline', 'target' => 'arbitrary-page'],
    ])))->toThrow(InvalidArgumentException::class, 'must be page')
        ->and(fn (): AgentToolDefinitionData => $normalizer->normalize(agentToolDefinitionFixture([
            'binding' => ['type' => 'endpoint', 'target' => '//foreign.test/agent'],
        ])))->toThrow(InvalidArgumentException::class, 'origin-relative')
        ->and(fn (): AgentToolDefinitionData => $normalizer->normalize(agentToolDefinitionFixture([
            'binding' => ['type' => 'endpoint', 'target' => 'https://foreign.test/agent'],
        ])))->toThrow(InvalidArgumentException::class, 'origin-relative')
        ->and(fn (): AgentToolDefinitionData => $normalizer->normalize(agentToolDefinitionFixture([
            'binding' => ['type' => 'endpoint', 'target' => '/agent#fragment'],
        ])))->toThrow(InvalidArgumentException::class, 'origin-relative')
        ->and(fn (): AgentToolDefinitionData => $normalizer->normalize(agentToolDefinitionFixture([
            'binding' => ['type' => 'form', 'target' => '#contact-form'],
        ])))->toThrow(InvalidArgumentException::class, 'stable identifier')
        ->and(fn (): AgentToolDefinitionData => $normalizer->normalize(agentToolDefinitionFixture([
            'binding' => ['type' => 'search', 'target' => 'Site Search'],
        ])))->toThrow(InvalidArgumentException::class, 'stable identifier');
});

it('accepts a read-only fragment binding addressed by a semantic element id', function (): void {
    $definition = (new AgentToolDefinitionNormalizer)->normalize(agentToolDefinitionFixture([
        'binding' => ['type' => 'fragment', 'target' => 'capell-social-feed-posts'],
    ]));

    expect($definition->binding->type)->toBe(AgentToolBindingType::Fragment)
        ->and($definition->binding->target)->toBe('capell-social-feed-posts');
});

it('rejects fragment bindings that write or address a record key', function (array $overrides, string $message): void {
    expect(fn (): AgentToolDefinitionData => (new AgentToolDefinitionNormalizer)->normalize(agentToolDefinitionFixture($overrides)))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'write effect' => [['effect' => 'write', 'binding' => ['type' => 'fragment', 'target' => 'capell-social-feed-posts']], 'Fragment agent tool bindings are read-only.'],
    'numeric record key' => [['binding' => ['type' => 'fragment', 'target' => 'social-feed-42']], 'Fragment agent tool binding target must be a semantic id without numbers.'],
    'selector' => [['binding' => ['type' => 'fragment', 'target' => '#posts > li']], 'Agent tool fragment id must be a stable identifier.'],
    'editor state' => [['binding' => ['type' => 'fragment', 'target' => 'wire:snapshot']], 'Agent tool fragment id must be a stable identifier.'],
]);
