<?php

declare(strict_types=1);

use Capell\Core\Concerns\HasModelRelations;
use Capell\Core\Octane\Resettable;
use Capell\Core\Support\CapellCoreManager;
use Capell\Core\ThemeStudio\Contracts\ThemeRuntimeSettings;
use Capell\Core\ThemeStudio\Settings\ThemeStudioSettings;
use Capell\Frontend\Contracts\AssetsRegistryInterface;
use Capell\Frontend\Support\Assets\FrontendAssetsService;
use Capell\Frontend\Support\Error\ErrorPageFallbackManifestStore;
use Capell\Frontend\Support\Logging\FrontendLogger;
use Capell\Frontend\Support\Security\FrontendUrlSignatureService;
use Capell\Installer\Support\Preflight\InstallerPreflight;
use Capell\Marketplace\Actions\PhoneHomeAction;
use Capell\Tests\Support\Octane\SingletonLifetime;
use Capell\Tests\Support\Octane\SingletonLifetimeGuard;
use Capell\Tests\Support\Octane\SingletonLifetimeInventory;

require_once dirname(__DIR__, 2) . '/Support/Octane/SingletonLifetime.php';
require_once dirname(__DIR__, 2) . '/Support/Octane/SingletonLifetimeInventory.php';
require_once dirname(__DIR__, 2) . '/Support/Octane/SingletonLifetimeGuard.php';

trait SingletonLifetimeTraitFixture
{
    private array $traitCache = [];
}

class SingletonLifetimeGrandparentFixture
{
    private array $privateParentCache = [];
}

class SingletonLifetimeParentFixture extends SingletonLifetimeGrandparentFixture
{
    protected array $parentCache = [];
}

final class SingletonLifetimeMutableDependencyFixture
{
    private array $values = [];
}

final class SingletonLifetimeFixture extends SingletonLifetimeParentFixture
{
    use SingletonLifetimeTraitFixture;

    private string $operation = '';

    public function __construct(private readonly SingletonLifetimeMutableDependencyFixture $dependency) {}
}

function singletonLifetimeGuard(): SingletonLifetimeGuard
{
    return new SingletonLifetimeGuard(dirname(__DIR__, 4), SingletonLifetimeInventory::dynamicBindingTargets());
}

it('classifies every mutable Capell production singleton with an exact concrete target', function (): void {
    $guard = singletonLifetimeGuard();
    $bindings = $guard->singletonTargets();
    $inventory = SingletonLifetimeInventory::mutableSingletons();
    $mutableBindings = collect($bindings)
        ->filter(static fn (array $binding, string $target): bool => $guard->mutableInstanceState($target) !== [])
        ->all();

    expect(array_diff_key($mutableBindings, $inventory))
        ->toBe([], 'Unclassified mutable singleton targets: ' . json_encode(array_diff_key($mutableBindings, $inventory), JSON_PRETTY_PRINT))
        ->and(array_diff_key($inventory, $bindings))
        ->toBe([], 'Classifications without a production singleton binding: ' . json_encode(array_keys(array_diff_key($inventory, $bindings)), JSON_PRETTY_PRINT));
});

it('enforces request mutable singleton reset protection without scoped dual registration', function (): void {
    $guard = singletonLifetimeGuard();
    $scopedTargets = $guard->scopedTargets();
    $taggedTargets = $guard->resettableTaggedTargets(Resettable::class);

    expect(array_intersect_key($scopedTargets, $taggedTargets))
        ->toBe([], 'A service cannot be both scoped and tagged for reset');

    foreach (SingletonLifetimeInventory::mutableSingletons() as $class => $classification) {
        expect(class_exists($class))->toBeTrue("Classified singleton [{$class}] must exist")
            ->and($classification['reason'])->not->toBeEmpty();

        if ($classification['lifetime'] !== SingletonLifetime::RequestMutable) {
            continue;
        }

        expect($scopedTargets)->not->toHaveKey($class, "Request-mutable singleton [{$class}] must not also be scoped");

        if ($classification['protection'] === 'tagged') {
            expect(is_a($class, Resettable::class, true))->toBeTrue("Tagged singleton [{$class}] must implement Resettable")
                ->and($taggedTargets)->toHaveKey($class, "Resettable singleton [{$class}] must be tagged in its provider");
        }

        if ($classification['protection'] === 'delegated') {
            expect(is_a(CapellCoreManager::class, Resettable::class, true))
                ->toBeTrue("Delegated singleton [{$class}] requires the tagged core manager flush");
        }
    }
});

it('scans every production source directory and resolves returned closure targets', function (): void {
    $guard = singletonLifetimeGuard();
    $singletons = $guard->singletonTargets();

    expect($singletons)
        ->toHaveKey(FrontendAssetsService::class)
        ->and($singletons[FrontendAssetsService::class]['abstract'])->toBe(AssetsRegistryInterface::class)
        ->and($singletons)->toHaveKey(FrontendUrlSignatureService::class)
        ->and($guard->scopedTargets()[ThemeStudioSettings::class]['abstract'])->toBe(ThemeRuntimeSettings::class)
        ->and($guard->bindingTargetsInSource(<<<'PHP'
            <?php
            $app->singleton(Contract::class, function () {
                $unrelated = new UnrelatedThing();

                return new ReturnedBinding();
            });
            PHP))->toBe(['ReturnedBinding']);

    expect(array_diff_key($guard->unresolvedClosureBindings(), SingletonLifetimeInventory::dynamicBindingTargets()))
        ->toBe([], 'Dynamic closure bindings require explicit abstract-to-concrete metadata');
});

it('characterizes singletonIf in the abstract package provider outside provider directories', function (): void {
    $source = (string) file_get_contents(dirname(__DIR__, 4) . '/core/src/Support/Packages/AbstractPackageServiceProvider.php');

    expect(singletonLifetimeGuard()->bindingTargetsInSource($source))
        ->toContain('Capell\Core\Support\PackageRegistry\CapellPackageRegistry');
});

it('keeps known process static hazards explicit and request-safe', function (): void {
    foreach ([PhoneHomeAction::class, InstallerPreflight::class, ErrorPageFallbackManifestStore::class, FrontendLogger::class] as $class) {
        expect(collect((new ReflectionClass($class))->getProperties(ReflectionProperty::IS_STATIC))->all())
            ->toBe([], "Known operation service [{$class}] must not retain static state");
    }

    $hazards = singletonLifetimeGuard()->mutatedStaticState();

    expect(array_keys($hazards))->toEqualCanonicalizing(array_keys(SingletonLifetimeInventory::mutableStaticState()))
        ->and(SingletonLifetimeInventory::mutableStaticState())->toHaveKey(HasModelRelations::class);
});

it('detects bounded static mutation forms and aliases', function (): void {
    $source = <<<'PHP'
        <?php
        namespace Fixtures;
        final class StaticMutations
        {
            private static array $values = [];
            private static int $counter = 0;
            private static object $store;
            public static function mutate(): void
            {
                self::$values = [];
                static::$values['key'] = true;
                StaticMutations::$values[] = 'value';
                self::$counter += 2;
                ++static::$counter;
                StaticMutations::$counter--;
                unset(self::$values['key']);
                self::$store->flush();
            }
        }
        PHP;

    expect(singletonLifetimeGuard()->staticMutationsInSource($source))
        ->toBe(['Fixtures\StaticMutations']);
});

it('detects private parent trait and readonly collaborator mutable state', function (): void {
    expect(singletonLifetimeGuard()->mutableInstanceState(SingletonLifetimeFixture::class))
        ->toContain(SingletonLifetimeFixture::class . '::$operation')
        ->toContain(SingletonLifetimeParentFixture::class . '::$parentCache')
        ->toContain(SingletonLifetimeGrandparentFixture::class . '::$privateParentCache')
        ->toContain(SingletonLifetimeTraitFixture::class . '::$traitCache')
        ->toContain(SingletonLifetimeFixture::class . '::$dependency->' . SingletonLifetimeMutableDependencyFixture::class . '::$values');
});
