<?php

declare(strict_types=1);

use Capell\Core\Contracts\Rendering\FrontendResourceContributor as CoreFrontendResourceContributor;
use Capell\Core\Data\Rendering\Assets\FrontendResourceContributionData as CoreFrontendResourceContributionData;
use Capell\Core\Data\Rendering\Assets\FrontendResourceData as CoreFrontendResourceData;
use Capell\Core\Data\Rendering\FrontendResourceContextData as CoreFrontendResourceContextData;
use Capell\Core\Data\Rendering\FrontendRuntimeManifestData as CoreFrontendRuntimeManifestData;
use Capell\Core\Enums\Rendering\FrontendResourceKind as CoreFrontendResourceKind;
use Capell\Core\Enums\Rendering\RenderingStrategyEnum as CoreRenderingStrategyEnum;
use Capell\Frontend\Actions\CollectFrontendResourceContributionsAction;
use Capell\Frontend\Contracts\FrontendResourceContributor;
use Capell\Frontend\Data\Assets\FrontendResourceContributionData;
use Capell\Frontend\Data\Assets\FrontendResourceData;
use Capell\Frontend\Data\FrontendResourceContextData;
use Capell\Frontend\Data\FrontendRuntimeManifestData;
use Capell\Frontend\Enums\FrontendResourceKind;
use Capell\Frontend\Enums\RenderingStrategyEnum;
use Symfony\Component\Process\Process;

describe('Core frontend resource contract', function (): void {
    it('lets a runtime package declare resources with Core loaded and Frontend absent', function (): void {
        $process = new Process([
            PHP_BINARY,
            '-r',
            <<<'PHP'
require $argv[1] . '/vendor/composer/ClassLoader.php';
$vendorMap = require $argv[1] . '/vendor/composer/autoload_classmap.php';
$vendorMap = array_filter($vendorMap, fn (string $class): bool => !str_starts_with($class, 'Capell\\'), ARRAY_FILTER_USE_KEY);
$psr4 = require $argv[1] . '/vendor/composer/autoload_psr4.php';
$loader = new \Composer\Autoload\ClassLoader;
$loader->addClassMap($vendorMap);
foreach ($psr4 as $prefix => $paths) {
    if (!str_starts_with($prefix, 'Capell\\')) {
        $loader->addPsr4($prefix, $paths);
    }
}
$loader->addPsr4('Capell\\Core\\', $argv[1] . '/packages/core/src');
$loader->register();
foreach (require $argv[1] . '/vendor/composer/autoload_files.php' as $file) {
    if (!str_contains($file, '/packages/')) {
        require_once $file;
    }
}
$contributor = new class implements \Capell\Core\Contracts\Rendering\FrontendResourceContributor {
    #[\Override]
    public function resources(\Capell\Core\Data\Rendering\FrontendResourceContextData $context): array {
        return [new \Capell\Core\Data\Rendering\Assets\FrontendResourceContributionData(
            \Capell\Core\Data\Rendering\Assets\FrontendResourceData::inlineStyle('vendor/runtime:card', 'vendor/runtime', '.card{}'),
        )];
    }
};
$context = new \Capell\Core\Data\Rendering\FrontendResourceContextData(null, null, null, null, null,
    \Capell\Core\Data\Rendering\FrontendRuntimeManifestData::forRenderingStrategy(\Capell\Core\Enums\Rendering\RenderingStrategyEnum::BladeOnly));
$resource = $contributor->resources($context)[0]->resource;
$frontendLoaded = array_filter(get_declared_classes(), fn (string $class): bool => str_starts_with($class, 'Capell\\Frontend\\'));
echo $resource->handle . ':' . $resource->kind->value . ':' . count($frontendLoaded);
PHP,
            dirname(__DIR__, 4),
        ]);
        $process->run();

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
            ->and($process->getOutput())->toBe('vendor/runtime:card:' . CoreFrontendResourceKind::InlineStyle->value . ':0');
    });

    it('loads the Frontend names first through a fresh authoritative Composer classmap', function (string $manifest, string $prefix): void {
        $process = new Process([
            PHP_BINARY,
            '-r',
            <<<'PHP'
require $argv[1] . '/vendor/composer/ClassLoader.php';
$vendorMap = require $argv[1] . '/vendor/composer/autoload_classmap.php';
$vendorMap = array_filter($vendorMap, fn (string $class): bool => !str_starts_with($class, 'Capell\\'), ARRAY_FILTER_USE_KEY);
$bootstrap = new \Composer\Autoload\ClassLoader;
$bootstrap->addClassMap($vendorMap);
$bootstrap->register();
$map = \Composer\ClassMapGenerator\ClassMapGenerator::createMap($argv[1] . '/packages/core/src');
$map += \Composer\ClassMapGenerator\ClassMapGenerator::createMap($argv[1] . '/packages/frontend/src');
foreach (spl_autoload_functions() as $autoload) {
    spl_autoload_unregister($autoload);
}
$loader = new \Composer\Autoload\ClassLoader;
$loader->addClassMap($map + $vendorMap);
$loader->setClassMapAuthoritative(true);
$loader->register();
foreach (require $argv[1] . '/vendor/composer/autoload_files.php' as $file) {
    if (!str_contains($file, '/packages/')) {
        require_once $file;
    }
}
$manifest = json_decode(file_get_contents($argv[1] . '/' . $argv[2]), true, flags: JSON_THROW_ON_ERROR);
foreach ($manifest['autoload']['files'] ?? [] as $file) {
    if (str_contains($file, 'src/Contracts/') || str_contains($file, 'src/Data/') || str_contains($file, 'src/Enums/')) {
        require_once $argv[1] . '/' . $argv[3] . $file;
    }
}
$contributor = new class implements \Capell\Frontend\Contracts\FrontendResourceContributor {
    #[\Override]
    public function resources(\Capell\Frontend\Data\FrontendResourceContextData $context): array {
        return [new \Capell\Frontend\Data\Assets\FrontendResourceContributionData(
            \Capell\Frontend\Data\Assets\FrontendResourceData::inlineStyle('vendor/legacy:card', 'vendor/legacy', '.legacy{}'),
        )];
    }
};
if (!$contributor instanceof \Capell\Core\Contracts\Rendering\FrontendResourceContributor) {
    throw new RuntimeException('A contributor written against the Frontend name must implement the Core contract.');
}
$context = new \Capell\Frontend\Data\FrontendResourceContextData(null, null, null, null, null,
    \Capell\Frontend\Data\FrontendRuntimeManifestData::forRenderingStrategy(\Capell\Frontend\Enums\RenderingStrategyEnum::BladeOnly));
$resource = $contributor->resources($context)[0]->resource;
if ($resource->kind !== \Capell\Frontend\Enums\FrontendResourceKind::InlineStyle) {
    throw new RuntimeException('The Frontend enum name must resolve to the same cases.');
}
echo $resource->handle;
PHP,
            dirname(__DIR__, 4),
            $manifest,
            $prefix,
        ]);
        $process->run();

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
            ->and($process->getOutput())->toBe('vendor/legacy:card');
    })->with([
        'aggregate' => ['composer.json', ''],
        'standalone frontend' => ['packages/frontend/composer.json', 'packages/frontend/'],
    ]);

    it('collects resources from a contributor written against the Core contract', function (): void {
        $contributor = new class implements CoreFrontendResourceContributor
        {
            #[Override]
            public function resources(CoreFrontendResourceContextData $context): array
            {
                return [new CoreFrontendResourceContributionData(
                    CoreFrontendResourceData::inlineStyle('vendor/runtime:core-card', 'vendor/runtime', '.core{}'),
                )];
            }
        };
        app()->instance('test.core-resource-contributor', $contributor);
        app()->tag(['test.core-resource-contributor'], CoreFrontendResourceContributor::TAG);

        $contributions = CollectFrontendResourceContributionsAction::run(new CoreFrontendResourceContextData(
            null,
            null,
            null,
            null,
            null,
            CoreFrontendRuntimeManifestData::forRenderingStrategy(CoreRenderingStrategyEnum::BladeOnly),
        ));

        expect(collect($contributions)->map(fn (CoreFrontendResourceContributionData $contribution): string => $contribution->resource->handle))
            ->toContain('vendor/runtime:core-card');
    });

    it('keeps the Frontend names as the same types', function (): void {
        $legacy = new FrontendResourceContributionData(
            FrontendResourceData::inlineStyle('vendor/legacy:card', 'vendor/legacy', '.legacy{}'),
        );
        $context = new FrontendResourceContextData(null, null, null, null, null, FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::BladeOnly));

        expect($legacy)->toBeInstanceOf(CoreFrontendResourceContributionData::class)
            ->and($context)->toBeInstanceOf(CoreFrontendResourceContextData::class)
            ->and(FrontendResourceKind::Style)->toBe(CoreFrontendResourceKind::Style)
            ->and(RenderingStrategyEnum::BladeOnly)->toBe(CoreRenderingStrategyEnum::BladeOnly)
            ->and(FrontendResourceContributor::TAG)->toBe(CoreFrontendResourceContributor::TAG);
    });
});
