<?php

declare(strict_types=1);

use Capell\Core\Actions\ValidateSiteSpecAction;
use Capell\Core\Contracts\SiteSpec\SiteSpecPresetProvider;
use Capell\Core\Data\SiteSpec\SiteSpecPresetData;
use Capell\Core\Support\SiteSpec\SiteSpecPresetRegistry;
use Capell\Core\Tests\Support\TaggedSiteSpecPresetProvider;

/** @return array<string, mixed> */
function presetSpec(SiteSpecPresetRegistry $registry, string $key): array
{
    $preset = $registry->get($key);

    throw_unless($preset instanceof SiteSpecPresetData, LogicException::class, sprintf('Missing preset [%s].', $key));

    return $preset->spec;
}

it('ships three presets that a guided setup can offer in a stable order', function (): void {
    $registry = new SiteSpecPresetRegistry(app());

    expect($registry->keys())->toBe(['service-business', 'shop-front', 'studio'])
        ->and(collect($registry->all())->every(fn (SiteSpecPresetData $preset): bool => $preset->label !== '' && $preset->description !== '' && $preset->businessTypes !== []))->toBeTrue();
});

it('gives every preset the five pages its business type needs, in navigation order', function (): void {
    $registry = new SiteSpecPresetRegistry(app());
    $slugs = fn (string $key): array => array_column(presetSpec($registry, $key)['pages'], 'slug');

    expect($slugs('service-business'))->toBe(['home', 'about', 'services', 'reviews', 'contact'])
        ->and($slugs('shop-front'))->toBe(['home', 'products', 'about', 'visit-us', 'contact'])
        ->and($slugs('studio'))->toBe(['home', 'work', 'services', 'about', 'contact']);
});

it('keeps every preset valid against the SiteSpec contract and private by default', function (string $key): void {
    $spec = presetSpec(new SiteSpecPresetRegistry(app()), $key);

    $result = ValidateSiteSpecAction::run($spec, ['default'], ['page'], ['content']);

    expect($result['errors'])->toBe([])
        ->and($result['valid'])->toBeTrue()
        ->and($spec['initialVisibility'])->toBe('private');
})->with(['service-business', 'shop-front', 'studio']);

it('writes draft copy for every section rather than placeholder text', function (string $key): void {
    $sections = collect(presetSpec(new SiteSpecPresetRegistry(app()), $key)['pages'])
        ->flatMap(fn (array $page): array => $page['sections']);

    expect($sections)->not->toBeEmpty()
        ->and($sections->every(fn (array $section): bool => mb_strlen(strip_tags($section['content'])) > 60 && ! str_contains(mb_strtolower($section['content']), 'lorem')))->toBeTrue();
})->with(['service-business', 'shop-front', 'studio']);

it('lets a theme or package register its own preset through the stable container tag', function (): void {
    app()->bind(TaggedSiteSpecPresetProvider::class);
    app()->tag([TaggedSiteSpecPresetProvider::class], SiteSpecPresetProvider::TAG);

    $registry = new SiteSpecPresetRegistry(app());
    $result = ValidateSiteSpecAction::run(presetSpec($registry, 'theme-landing'), [], [], []);

    expect(SiteSpecPresetProvider::TAG)->toBe('capell.site-spec.preset-provider')
        ->and($registry->has('theme-landing'))->toBeTrue()
        ->and($registry->keys())->toBe(['service-business', 'shop-front', 'studio', 'theme-landing'])
        ->and($result['valid'])->toBeTrue();
});

it('rejects a second preset registered under the same key', function (): void {
    $registry = new SiteSpecPresetRegistry(app());
    $duplicate = new SiteSpecPresetData(key: 'studio', label: 'Another', description: 'Clashes with a Core preset.', spec: []);

    expect(fn () => $registry->register($duplicate))->toThrow(LogicException::class, 'already registered');
});

it('discards preset registrations between scoped lifecycles', function (): void {
    $first = resolve(SiteSpecPresetRegistry::class);
    $first->register(new SiteSpecPresetData(key: 'scoped-only', label: 'Scoped', description: 'Exists for one lifecycle.', spec: []));

    expect(resolve(SiteSpecPresetRegistry::class))->toBe($first)
        ->and($first->has('scoped-only'))->toBeTrue();

    app()->forgetScopedInstances();

    expect(resolve(SiteSpecPresetRegistry::class)->has('scoped-only'))->toBeFalse();
});
