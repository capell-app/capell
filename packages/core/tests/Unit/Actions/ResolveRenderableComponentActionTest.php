<?php

declare(strict_types=1);

use Capell\Core\Actions\ResolveRenderableComponentAction;
use Capell\Core\Data\RenderableDefinitionData;
use Capell\Core\Support\Renderables\RenderableRegistry;

it('resolves the requested renderable implementation', function (): void {
    resolve(RenderableRegistry::class)->register(new RenderableDefinitionData(
        key: 'vendor.renderable.hero',
        type: 'vendor-renderable',
        blade: 'vendor::renderable.hero',
        adminPreview: 'capell-admin::previews.default',
        assetComponent: 'vendor::renderable.hero-component',
    ));

    expect(ResolveRenderableComponentAction::run('vendor-renderable', 'vendor.renderable.hero'))
        ->toBe('vendor::renderable.hero')
        ->and(ResolveRenderableComponentAction::run('vendor-renderable', 'vendor.renderable.hero', 'adminPreview'))
        ->toBe('capell-admin::previews.default')
        ->and(ResolveRenderableComponentAction::run('vendor-renderable', 'vendor.renderable.hero', 'component'))
        ->toBe('vendor::renderable.hero-component');
});

it('fails when the requested implementation is not defined', function (): void {
    resolve(RenderableRegistry::class)->register(new RenderableDefinitionData(
        key: 'vendor.renderable.hero',
        type: 'vendor-renderable',
        blade: 'vendor::renderable.hero',
    ));

    expect(fn (): string => ResolveRenderableComponentAction::run('vendor-renderable', 'vendor.renderable.hero', 'livewire'))
        ->toThrow(InvalidArgumentException::class, 'Renderable [vendor.renderable.hero] does not define [livewire].');
});
