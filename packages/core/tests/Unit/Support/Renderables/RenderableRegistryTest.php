<?php

declare(strict_types=1);

use Capell\Core\Actions\ResolveRenderableViewDataAction;
use Capell\Core\Data\RenderableDefinitionData;
use Capell\Core\Support\Renderables\RenderableRegistry;
use Capell\Core\Support\Renderables\RenderableViewDataContext;
use Capell\Core\Support\Renderables\RenderableViewDataResolver;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

it('stores renderables by type and stable key', function (): void {
    $registry = new RenderableRegistry;

    $definition = new RenderableDefinitionData(
        key: 'vendor.renderable.hero',
        type: 'vendor-renderable',
        blade: 'vendor::renderable.hero',
    );

    $registry->register($definition);

    expect($registry->get('vendor-renderable', 'vendor.renderable.hero'))->toBe($definition)
        ->and($registry->allForType('vendor-renderable'))->toBe([
            'vendor.renderable.hero' => $definition,
        ]);
});

it('fails clearly for unknown renderables', function (): void {
    $registry = new RenderableRegistry;

    expect(fn (): RenderableDefinitionData => $registry->get('vendor-renderable', 'vendor.renderable.missing'))
        ->toThrow(InvalidArgumentException::class, 'Renderable [vendor.renderable.missing] of type [vendor-renderable] is not registered.');
});

it('resolves optional renderable view data', function (): void {
    $legacyDefinition = new RenderableDefinitionData(
        key: 'vendor.renderable.legacy',
        type: 'vendor-renderable',
        blade: 'vendor::renderable.legacy',
    );

    expect($legacyDefinition->resolveViewData(renderableViewDataTestContext()))->toBe([]);

    $definition = new RenderableDefinitionData(
        key: 'vendor.renderable.hero',
        type: 'vendor-renderable',
        blade: 'vendor::renderable.hero',
        viewDataResolver: RenderableRegistryTestViewDataResolver::class,
    );

    expect($definition->resolveViewData(renderableViewDataTestContext()))
        ->toBe([
            'renderKey' => 'hero',
            'headline' => 'Resolver headline',
        ]);
});

it('merges resolver data without allowing canonical view variables to be overridden', function (): void {
    $definition = new RenderableDefinitionData(
        key: 'vendor.renderable.hero',
        type: 'vendor-renderable',
        blade: 'vendor::renderable.hero',
        viewDataResolver: RenderableRegistryCanonicalTestViewDataResolver::class,
    );
    $context = renderableViewDataTestContext();

    expect(ResolveRenderableViewDataAction::run(
        definition: $definition,
        asset: $context->asset,
        translation: $context->translation,
        meta: $context->meta,
        dynamicData: $context->dynamicData,
        renderKey: $context->renderKey,
    ))
        ->asset->toBe($context->asset)
        ->translation->toBe($context->translation)
        ->meta->toBe($context->meta)
        ->dynamicData->toBe($context->dynamicData)
        ->renderKey->toBe($context->renderKey)
        ->headline->toBe('Resolver headline');
});

function renderableViewDataTestContext(): RenderableViewDataContext
{
    $asset = new class extends Model
    {
        use HasFactory;

        protected $guarded = [];
    };

    $translation = new class extends Model
    {
        use HasFactory;

        protected $guarded = [];
    };

    return new RenderableViewDataContext(
        asset: $asset,
        translation: $translation,
        meta: ['headline' => 'Resolver headline'],
        dynamicData: [],
        renderKey: 'hero',
    );
}

final class RenderableRegistryTestViewDataResolver implements RenderableViewDataResolver
{
    /**
     * @return array<string, mixed>
     */
    public function data(RenderableViewDataContext $context): array
    {
        return [
            'renderKey' => $context->renderKey,
            'headline' => $context->meta['headline'],
        ];
    }
}

final class RenderableRegistryCanonicalTestViewDataResolver implements RenderableViewDataResolver
{
    /**
     * @return array<string, mixed>
     */
    public function data(RenderableViewDataContext $context): array
    {
        return [
            'asset' => 'overridden asset',
            'translation' => 'overridden translation',
            'meta' => ['overridden' => true],
            'dynamicData' => ['overridden' => true],
            'renderKey' => 'overridden',
            'headline' => $context->meta['headline'],
        ];
    }
}
