<?php

declare(strict_types=1);

use Capell\Core\Contracts\Rendering\RenderHookExtensionInterface as CoreRenderHookExtension;
use Capell\Core\Data\Rendering\RenderHookContext as CoreRenderHookContext;
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
});
