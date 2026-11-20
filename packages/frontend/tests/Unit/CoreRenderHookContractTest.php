<?php

declare(strict_types=1);

use Capell\Core\Contracts\Rendering\RenderHookExtensionInterface as CoreRenderHookExtension;
use Capell\Core\Data\Rendering\RenderHookContext as CoreRenderHookContext;
use Capell\Frontend\Contracts\RenderHookExtensionInterface;
use Capell\Frontend\Data\RenderHookContext;
use Capell\Frontend\Data\RenderHookContributionData;
use Capell\Frontend\Enums\RenderHookLocation;
use Capell\Frontend\Support\Render\FrontendHookRegistrar;
use Capell\Frontend\Support\Render\RenderHookRegistry;
use Symfony\Component\Process\Process;

describe('Core render hook contract', function (): void {
    it('executes a runtime hook with only the Core contract and context loaded', function (): void {
        $process = new Process([
            PHP_BINARY,
            '-r',
            <<<'PHP'
require $argv[1];
require $argv[2];
$hook = new class implements \Capell\Core\Contracts\Rendering\RenderHookExtensionInterface {
    #[\Override]
    public function render(\Capell\Core\Data\Rendering\RenderHookContext $context): string {
        return '<em>' . $context->location . ':' . $context->item['name'] . '</em>';
    }
};
echo $hook->render(new \Capell\Core\Data\Rendering\RenderHookContext('footer', ['name' => 'Ada']));
PHP,
            dirname(__DIR__, 3) . '/core/src/Contracts/Rendering/RenderHookExtensionInterface.php',
            dirname(__DIR__, 3) . '/core/src/Data/Rendering/RenderHookContext.php',
        ]);
        $process->run();

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
            ->and($process->getOutput())->toBe('<em>footer:Ada</em>');
    });

    it('loads legacy hooks first through a fresh authoritative Composer classmap', function (string $manifest, string $prefix): void {
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
$manifest = json_decode(file_get_contents($argv[1] . '/' . $argv[2]), true, flags: JSON_THROW_ON_ERROR);
foreach ($manifest['autoload']['files'] ?? [] as $file) {
    if (str_contains($file, 'RenderHook')) {
        require $argv[1] . '/' . $argv[3] . $file;
    }
}
$hook = new class implements \Capell\Frontend\Contracts\RenderHookExtensionInterface {
    #[\Override]
    public function render(\Capell\Frontend\Data\RenderHookContext $context): string {
        return '<b>' . $context->item['name'] . '</b>';
    }
};
if (!$hook instanceof \Capell\Core\Contracts\Rendering\RenderHookExtensionInterface) {
    throw new RuntimeException('The legacy hook must implement the Core contract.');
}
$context = new class('footer', ['name' => 'Grace']) extends \Capell\Frontend\Data\RenderHookContext {
    public string $prepared = 'subclass';
};
if (!$context instanceof \Capell\Core\Data\Rendering\RenderHookContext || $context->prepared !== 'subclass') {
    throw new RuntimeException('The legacy context subclass must retain its Core type and prepared data.');
}
echo $hook->render($context);
PHP,
            dirname(__DIR__, 4),
            $manifest,
            $prefix,
        ]);
        $process->run();

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
            ->and($process->getOutput())->toBe('<b>Grace</b>');
    })->with([
        'aggregate' => ['composer.json', ''],
        'standalone frontend' => ['packages/frontend/composer.json', 'packages/frontend/'],
    ]);

    it('renders a hook written against the Core contract through each registration entry point', function (string $registration): void {
        $registry = new RenderHookRegistry;
        $hook = new class implements CoreRenderHookExtension
        {
            #[Override]
            public function render(CoreRenderHookContext $context): string
            {
                return '<em>' . $context->location . ':' . $context->item['name'] . '</em>';
            }
        };

        match ($registration) {
            'legacy' => $registry->register(RenderHookLocation::Footer, $hook),
            'extension' => $registry->registerExtension(RenderHookLocation::Footer, $hook),
            'contribution' => $registry->contribute(RenderHookContributionData::extension(RenderHookLocation::Footer, $hook, 'vendor/example', 'example')),
            'class contribution' => $registry->contribute(new RenderHookContributionData(RenderHookLocation::Footer, $hook::class, 'vendor/example', 'example')),
            'registrar' => resolve(FrontendHookRegistrar::class)->contribute(RenderHookLocation::Footer, $hook, 'vendor/example', 'example'),
            default => throw new InvalidArgumentException('Unknown hook registration entry point.'),
        };
        if ($registration === 'registrar') {
            $registry = resolve(RenderHookRegistry::class);
        }

        expect($registry->renderAll(RenderHookLocation::Footer, ['name' => 'Ada']))
            ->toBe('<em>' . RenderHookLocation::Footer->value . ':Ada</em>');
    })->with(['legacy', 'extension', 'contribution', 'class contribution', 'registrar']);

    it('keeps hooks written against the Frontend names working as the same types', function (): void {
        $legacy = new class implements RenderHookExtensionInterface
        {
            #[Override]
            public function render(RenderHookContext $context): string
            {
                return '<b>' . $context->item['name'] . '</b>';
            }
        };

        $registry = new RenderHookRegistry;
        $registry->register(RenderHookLocation::Footer, $legacy);

        expect($legacy)->toBeInstanceOf(CoreRenderHookExtension::class)
            ->and(new RenderHookContext('footer', null))->toBeInstanceOf(CoreRenderHookContext::class)
            ->and($registry->renderAll(RenderHookLocation::Footer, ['name' => 'Grace']))->toBe('<b>Grace</b>');
    });
});
