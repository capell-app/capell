<?php

declare(strict_types=1);

use Capell\Admin\Data\Configurators\ConfiguratorContextData;
use Capell\Admin\Enums\ConfiguratorTypeEnum;

it('creates scalar create context for a target type', function (): void {
    $context = ConfiguratorContextData::forCreate(
        target: ConfiguratorTypeEnum::Page,
        typeKey: 'landing-page',
        resourceName: 'default',
    );

    expect($context->target)->toBe(ConfiguratorTypeEnum::Page)
        ->and($context->operation)->toBe('create')
        ->and($context->typeKey)->toBe('landing-page')
        ->and($context->resourceName)->toBe('default');
});

it('creates scalar edit context without a type key', function (): void {
    $context = ConfiguratorContextData::forEdit(
        target: ConfiguratorTypeEnum::Page,
        resourceName: 'default',
    );

    expect($context->target)->toBe(ConfiguratorTypeEnum::Page)
        ->and($context->operation)->toBe('edit')
        ->and($context->typeKey)->toBeNull()
        ->and($context->resourceName)->toBe('default');
});

it('creates embedded select edit context with record identity', function (): void {
    $context = ConfiguratorContextData::forEmbeddedSelectEdit(
        target: ConfiguratorTypeEnum::Blueprint,
        resourceName: 'widget',
        recordName: 'System',
        recordKey: 'system',
        recordType: 'widget',
    );

    expect($context->target)->toBe(ConfiguratorTypeEnum::Blueprint)
        ->and($context->operation)->toBe('edit')
        ->and($context->typeKey)->toBeNull()
        ->and($context->resourceName)->toBe('widget')
        ->and($context->embeddedSelectEdit)->toBeTrue()
        ->and($context->recordName)->toBe('System')
        ->and($context->recordKey)->toBe('system')
        ->and($context->recordType)->toBe('widget');
});
