<?php

declare(strict_types=1);

use Capell\Frontend\Contracts\RenderHookExtensionInterface;
use Capell\Frontend\Data\RenderHookContext;
use Capell\Frontend\Enums\RenderHookLocation;
use Capell\Frontend\Support\Render\RenderHookRegistry;
use Illuminate\Support\Facades\Blade;

describe('RenderHookRegistry', function (): void {
    it('renders nothing if no extensions are registered for a location', function (): void {
        $registry = new RenderHookRegistry;
        expect($registry->renderAll(RenderHookLocation::BeforeTitle, ['bar' => 'baz']))->toBe('');
    });

    it('renders a single extension output for a location', function (): void {
        $registry = new RenderHookRegistry;
        $registry->register(RenderHookLocation::BeforeTitle, fn (RenderHookContext $ctx): string => '<span>' . $ctx->item['foo'] . '</span>');

        expect($registry->renderAll(RenderHookLocation::BeforeTitle, ['foo' => 'bar']))->toBe('<span>bar</span>');
    });

    it('renders multiple extensions in priority order for a location', function (): void {
        $registry = new RenderHookRegistry;
        $registry->register(RenderHookLocation::Footer, fn (RenderHookContext $ctx): string => '<a>' . $ctx->item['foo'] . '</a>', 20);
        $registry->register(RenderHookLocation::Footer, fn (RenderHookContext $ctx): string => '<b>' . $ctx->item['foo'] . '</b>', 5);

        expect($registry->renderAll(RenderHookLocation::Footer, ['foo' => 'baz']))->toBe('<b>baz</b><a>baz</a>');
    });

    it('supports class-based extensions via interface', function (): void {
        $registry = new RenderHookRegistry;
        $registry->register(RenderHookLocation::AfterTitle, new class implements RenderHookExtensionInterface
        {
            public function render(RenderHookContext $context): string
            {
                return '<i>' . $context->item['foo'] . '</i>';
            }
        });
        expect($registry->renderAll(RenderHookLocation::AfterTitle, ['foo' => 'qux']))->toBe('<i>qux</i>');
    });

    it('passes the context to each extension for a location', function (): void {
        $registry = new RenderHookRegistry;
        $registry->register(RenderHookLocation::Footer, fn (RenderHookContext $ctx): string => $ctx->item['id'] === 1 ? 'one' : 'other');
        expect($registry->renderAll(RenderHookLocation::Footer, ['id' => 1]))->toBe('one')
            ->and($registry->renderAll(RenderHookLocation::Footer, ['id' => 2]))->toBe('other');
    });

    it('supports Blade component name as extension', function (): void {
        $registry = new RenderHookRegistry;
        $registry->register(RenderHookLocation::AfterResult, 'test-component');
        Blade::shouldReceive('render')->once()->with('test-component', Mockery::on(fn (array $data): bool => isset($data['context']) && $data['context'] instanceof RenderHookContext && $data['context']->location === RenderHookLocation::AfterResult->value))->andReturn('<blade>');
        expect($registry->renderAll(RenderHookLocation::AfterResult, ['foo' => 'bar']))->toBe('<blade>');
    });

    it('renders scenario-specific extensions only', function (): void {
        $registry = new RenderHookRegistry;
        $registry->register(RenderHookLocation::AfterTitle, fn (RenderHookContext $ctx): string => 'A', 10, 'asset');
        $registry->register(RenderHookLocation::AfterTitle, fn (RenderHookContext $ctx): string => 'B', 10, 'page');
        expect($registry->renderAll(RenderHookLocation::AfterTitle, [], 'asset'))->toBe('A')
            ->and($registry->renderAll(RenderHookLocation::AfterTitle, [], 'page'))->toBe('B')
            ->and($registry->renderAll(RenderHookLocation::AfterTitle, []))->toBe('');
    });

    it('renders target-specific extensions only', function (): void {
        $registry = new RenderHookRegistry;
        $registry->register(RenderHookLocation::AfterTitle, fn (RenderHookContext $ctx): string => 'A', 10, 'asset', 'asset/tile.blade.php');
        $registry->register(RenderHookLocation::AfterTitle, fn (RenderHookContext $ctx): string => 'B', 10, 'asset', 'asset/index.blade.php');
        expect($registry->renderAll(RenderHookLocation::AfterTitle, [], 'asset', 'asset/tile.blade.php'))->toBe('A')
            ->and($registry->renderAll(RenderHookLocation::AfterTitle, [], 'asset', 'asset/index.blade.php'))->toBe('B')
            ->and($registry->renderAll(RenderHookLocation::AfterTitle, [], 'asset'))->toBe('');
    });
});
