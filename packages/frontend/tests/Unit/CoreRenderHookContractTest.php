<?php

declare(strict_types=1);

use Capell\Core\Contracts\Rendering\RenderHookExtensionInterface as CoreRenderHookExtension;
use Capell\Core\Data\Rendering\RenderHookContext as CoreRenderHookContext;
use Capell\Frontend\Contracts\RenderHookExtensionInterface;
use Capell\Frontend\Data\RenderHookContext;
use Capell\Frontend\Enums\RenderHookLocation;
use Capell\Frontend\Support\Render\RenderHookRegistry;

describe('Core render hook contract', function (): void {
    it('renders a hook written against the Core contract, without Frontend types', function (): void {
        $registry = new RenderHookRegistry;
        $registry->register(RenderHookLocation::Footer, new class implements CoreRenderHookExtension
        {
            public function render(CoreRenderHookContext $context): string
            {
                return '<em>' . $context->location . ':' . $context->item['name'] . '</em>';
            }
        });

        expect($registry->renderAll(RenderHookLocation::Footer, ['name' => 'Ada']))
            ->toBe('<em>' . RenderHookLocation::Footer->value . ':Ada</em>');
    });

    it('keeps hooks written against the Frontend names working as the same types', function (): void {
        $legacy = new class implements RenderHookExtensionInterface
        {
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
