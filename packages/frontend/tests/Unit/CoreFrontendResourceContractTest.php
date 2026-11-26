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
    if (!str_starts_with($file, $argv[1] . '/packages/')) {
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

    it('declares resources with the Core types, and Frontend keeps no copies of them', function (): void {
        $contribution = new CoreFrontendResourceContributionData(
            CoreFrontendResourceData::inlineStyle('vendor/runtime:plan-card', 'vendor/runtime', '.plan{}'),
        );

        expect($contribution->resource->kind)->toBe(CoreFrontendResourceKind::InlineStyle)
            ->and(CoreFrontendResourceContributor::TAG)->toBe('capell.frontend.resource-contributor')
            ->and(interface_exists('Capell\\Frontend\\Contracts\\FrontendResourceContributor'))->toBeFalse()
            ->and(class_exists('Capell\\Frontend\\Data\\Assets\\FrontendResourceData'))->toBeFalse()
            ->and(enum_exists('Capell\\Frontend\\Enums\\FrontendResourceKind'))->toBeFalse();
    });
});
