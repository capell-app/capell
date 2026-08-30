<?php

declare(strict_types=1);

use Capell\Core\Contracts\Extensions\RecordsExtensionContributionReceipt;
use Capell\Core\Enums\ExtensionContributionType;
use Capell\Core\Support\Extensions\ExtensionContributionReceiptContext;
use Capell\Core\Support\Extensions\ExtensionContributionReceiptRegistry;
use Capell\Core\Support\Extensions\ExtensionPosition;
use Capell\Frontend\Contracts\RenderHookExtensionInterface;
use Capell\Frontend\Data\MainContentRenderHookData;
use Capell\Frontend\Data\RenderHookContext;
use Capell\Frontend\Data\RenderHookContributionData;
use Capell\Frontend\Enums\RenderHookLocation;
use Capell\Frontend\Enums\RenderHookRegistrationType;
use Capell\Frontend\Support\Render\FrontendHookRegistrar;
use Capell\Frontend\Support\Render\RenderHookRegistry;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;

it('registers and retrieves hooks; handles collisions', function (): void {
    $registry = new RenderHookRegistry;

    $registry->register(RenderHookLocation::BeforeTitle, fn (): string => 'A');
    $registry->register(RenderHookLocation::BeforeTitle, fn (): string => 'B');

    $hooks = $registry->get(RenderHookLocation::BeforeTitle);

    expect($hooks)->toBeArray()
        ->and(count($hooks))->toBe(2)
        ->and($hooks[0]())->toBe('A')
        ->and($hooks[1]())->toBe('B');
});

it('emits a receipt at the direct render hook boundary', function (): void {
    $receipts = new ExtensionContributionReceiptRegistry;
    app()->instance(ExtensionContributionReceiptRegistry::class, $receipts);
    $registry = new RenderHookRegistry;

    $receipts->withContext(
        ExtensionContributionReceiptContext::forPackage('vendor/frontend-hooks', 'frontend', 'Vendor\\FrontendServiceProvider'),
        function () use ($registry): void {
            $registry->registerView(RenderHookLocation::Footer, 'vendor::footer');
        },
    );

    expect($receipts->forPackage('vendor/frontend-hooks'))->toHaveCount(1)
        ->and($receipts->forPackage('vendor/frontend-hooks')[0]->type)->toBe(ExtensionContributionType::RenderHook);
});

it('preserves one-argument construction for direct package callers', function (): void {
    $receipts = new ExtensionContributionReceiptRegistry;
    app()->instance(RecordsExtensionContributionReceipt::class, $receipts);
    $registry = new RenderHookRegistry;
    $registrar = new FrontendHookRegistrar($registry);
    $extension = new class implements RenderHookExtensionInterface
    {
        public function render(RenderHookContext $context): string
        {
            return '<aside>compatibility hook</aside>';
        }
    };

    $receipts->withContext(
        ExtensionContributionReceiptContext::forPackage('vendor/frontend-hooks', 'frontend', 'Vendor\\FrontendServiceProvider'),
        function () use ($registrar, $extension): void {
            $registrar->contribute(
                RenderHookLocation::Footer,
                $extension,
                'vendor/frontend-hooks',
                'compatibility-hook',
            );
        },
    );

    expect($registry->renderAll(RenderHookLocation::Footer))
        ->toBe('<aside>compatibility hook</aside>')
        ->and(collect($receipts->forPackage('vendor/frontend-hooks'))->contains(
            static fn (object $receipt): bool => $receipt->key === 'compatibility-hook'
                && $receipt->sourceClass === FrontendHookRegistrar::class,
        ))->toBeTrue();
});

it('allows one-argument construction without a receipt binding', function (): void {
    app()->instance(RecordsExtensionContributionReceipt::class, null);
    app()->offsetUnset(RecordsExtensionContributionReceipt::class);

    expect(app()->bound(RecordsExtensionContributionReceipt::class))->toBeFalse();

    $registry = new RenderHookRegistry;
    $registrar = new FrontendHookRegistrar($registry);
    $extension = new class implements RenderHookExtensionInterface
    {
        public function render(RenderHookContext $context): string
        {
            return '<aside>standalone hook</aside>';
        }
    };

    $registrar->contribute(
        RenderHookLocation::Footer,
        $extension,
        'vendor/frontend-hooks',
        'standalone-hook',
    );

    expect($registry->renderAll(RenderHookLocation::Footer))->toBe('<aside>standalone hook</aside>');
});

it('emits distinct receipts for unkeyed closures at one hook location', function (): void {
    $receipts = new ExtensionContributionReceiptRegistry;
    app()->instance(ExtensionContributionReceiptRegistry::class, $receipts);
    $registry = new RenderHookRegistry;

    $receipts->withContext(
        ExtensionContributionReceiptContext::forPackage('vendor/frontend-hooks', 'frontend', 'Vendor\\FrontendServiceProvider'),
        function () use ($registry): void {
            $registry->registerCallable(RenderHookLocation::Footer, static fn (): string => 'one');
            $registry->registerCallable(RenderHookLocation::Footer, static fn (): string => 'two');
        },
    );

    expect($receipts->forPackage('vendor/frontend-hooks'))->toHaveCount(2)
        ->and($receipts->forPackage('vendor/frontend-hooks')[0]->key)
        ->not->toBe($receipts->forPackage('vendor/frontend-hooks')[1]->key);
});

it('deduplicates keyed contributions by stable key and exposes diagnostics', function (): void {
    $registry = new RenderHookRegistry;

    $extension = new class implements RenderHookExtensionInterface
    {
        public function render(RenderHookContext $context): string
        {
            return '<aside>banner</aside>';
        }
    };

    $contribution = new RenderHookContributionData(
        location: RenderHookLocation::Footer,
        extension: $extension,
        owner: 'capell-app/campaign-studio',
        key: 'footer-banner',
        cacheSafe: false,
    );

    $registry->contribute($contribution);
    $registry->contribute($contribution);

    expect($registry->get(RenderHookLocation::Footer))->toHaveCount(1)
        ->and($registry->renderAll(RenderHookLocation::Footer))->toBe('<aside>banner</aside>');

    $diagnostics = $registry->contributions();

    expect($diagnostics['footer'])->toHaveCount(1)
        ->and($diagnostics['footer'][0]['owner'])->toBe('capell-app/campaign-studio')
        ->and($diagnostics['footer'][0]['key'])->toBe('footer-banner')
        ->and($diagnostics['footer'][0]['registrationType'])->toBe(RenderHookRegistrationType::ExtensionClass->value)
        ->and($diagnostics['footer'][0]['cacheSafe'])->toBeFalse();
});

it('deduplicates equivalent contributions across provider bootstrap callbacks', function (): void {
    $extensionFactory = static fn (string $variant): RenderHookExtensionInterface => new readonly class($variant) implements RenderHookExtensionInterface
    {
        public function __construct(private string $variant) {}

        public function render(RenderHookContext $context): string
        {
            return $this->variant;
        }
    };

    $container = new Container;
    $container->singleton(RenderHookRegistry::class);
    $container->singleton(FrontendHookRegistrar::class, static fn (Container $container): FrontendHookRegistrar => new FrontendHookRegistrar(
        $container->make(RenderHookRegistry::class),
        new ExtensionContributionReceiptRegistry,
    ));
    $container->afterResolving(RenderHookRegistry::class, static function (RenderHookRegistry $registry) use ($extensionFactory): void {
        $registry->contribute(RenderHookContributionData::extension(
            location: RenderHookLocation::Footer,
            extension: $extensionFactory('default'),
            owner: 'capell-app/navigation',
            key: 'foundation-header-navigation-default',
        ));
    });
    $container->afterResolving(RenderHookRegistry::class, static function (RenderHookRegistry $registry) use ($container, $extensionFactory): void {
        $container->make(FrontendHookRegistrar::class)->contribute(
            location: RenderHookLocation::Footer,
            extension: $extensionFactory('default'),
            owner: 'capell-app/navigation',
            key: 'foundation-header-navigation-default',
        );
    });

    $registry = $container->make(RenderHookRegistry::class);

    expect($registry->get(RenderHookLocation::Footer))->toHaveCount(1);

    expect(function () use ($container, $extensionFactory): void {
        $container->make(FrontendHookRegistrar::class)->contribute(
            location: RenderHookLocation::Footer,
            extension: $extensionFactory('changed'),
            owner: 'capell-app/navigation',
            key: 'foundation-header-navigation-default',
        );
    })->toThrow(LogicException::class, 'foundation-header-navigation-default');
});

it('resolves class-string extensions from the current container scope', function (): void {
    $instances = 0;

    app()->scoped('testing.scoped-render-hook', function () use (&$instances): RenderHookExtensionInterface {
        $instance = ++$instances;

        return new readonly class($instance) implements RenderHookExtensionInterface
        {
            public function __construct(private int $instance) {}

            public function render(RenderHookContext $context): string
            {
                return '<span>' . $this->instance . '</span>';
            }
        };
    });

    $registry = new RenderHookRegistry(app());
    $registry->contribute(new RenderHookContributionData(
        location: RenderHookLocation::Footer,
        extension: 'testing.scoped-render-hook',
        owner: 'capell-app/example',
        key: 'scoped-footer',
        registrationType: RenderHookRegistrationType::ExtensionClass,
    ));

    expect($registry->renderAll(RenderHookLocation::Footer))->toBe('<span>1</span>')
        ->and($registry->renderAll(RenderHookLocation::Footer))->toBe('<span>1</span>');

    app()->forgetScopedInstances();

    expect($registry->renderAll(RenderHookLocation::Footer))->toBe('<span>2</span>')
        ->and($instances)->toBe(2);
});

it('renders keyed explicit contribution types with dedupe and diagnostics', function (): void {
    $registry = new RenderHookRegistry;
    $viewPath = sys_get_temp_dir() . '/capell-keyed-render-hook-views-' . bin2hex(random_bytes(6));

    File::ensureDirectoryExists($viewPath);
    File::put($viewPath . '/footer.blade.php', '<span>keyed view: {{ $context->location }}</span>');
    View::addNamespace('keyed-render-hook-test', $viewPath);

    $extension = new class implements RenderHookExtensionInterface
    {
        public function render(RenderHookContext $context): string
        {
            return '<span>keyed class: ' . $context->location . '</span>';
        }
    };

    $registry->contribute(RenderHookContributionData::view(
        location: RenderHookLocation::Footer,
        view: 'keyed-render-hook-test::footer',
        owner: 'capell-app/example',
        key: 'view-footer',
    ));
    $registry->contribute(RenderHookContributionData::view(
        location: RenderHookLocation::Footer,
        view: 'keyed-render-hook-test::footer',
        owner: 'capell-app/example',
        key: 'view-footer',
    ));
    $registry->contribute(RenderHookContributionData::inlineBlade(
        location: RenderHookLocation::Footer,
        blade: '<span>keyed inline: {{ $context->location }}</span>',
        owner: 'capell-app/example',
        key: 'inline-footer',
    ));
    $registry->contribute(RenderHookContributionData::extension(
        location: RenderHookLocation::Footer,
        extension: $extension,
        owner: 'capell-app/example',
        key: 'class-footer',
    ));

    expect($registry->renderAll(RenderHookLocation::Footer))->toBe(
        '<span>keyed view: footer</span>'
        . '<span>keyed inline: footer</span>'
        . '<span>keyed class: footer</span>',
    );

    $diagnostics = $registry->contributions()[RenderHookLocation::Footer->value];

    expect($diagnostics)->toHaveCount(3)
        ->and(array_column($diagnostics, 'registrationType'))->toBe([
            RenderHookRegistrationType::View->value,
            RenderHookRegistrationType::InlineBlade->value,
            RenderHookRegistrationType::ExtensionClass->value,
        ]);

    File::deleteDirectory($viewPath);
});

it('registers explicit view, inline blade, callable, and class render hooks distinctly', function (): void {
    $registry = new RenderHookRegistry;
    $viewPath = sys_get_temp_dir() . '/capell-render-hook-views-' . bin2hex(random_bytes(6));

    File::ensureDirectoryExists($viewPath);
    File::put($viewPath . '/footer.blade.php', '<span>view: {{ $context->location }}</span>');
    View::addNamespace('render-hook-test', $viewPath);

    $extension = new class implements RenderHookExtensionInterface
    {
        public function render(RenderHookContext $context): string
        {
            return '<span>class: ' . $context->location . '</span>';
        }
    };

    $registry->registerView(RenderHookLocation::Footer, 'render-hook-test::footer');
    $registry->registerInlineBlade(RenderHookLocation::Footer, '<span>inline: {{ $context->location }}</span>');
    $registry->registerCallable(RenderHookLocation::Footer, fn (RenderHookContext $context): string => '<span>callable: ' . $context->location . '</span>');
    $registry->registerExtension(RenderHookLocation::Footer, $extension);

    expect($registry->renderAll(RenderHookLocation::Footer))->toBe(
        '<span>view: footer</span>'
        . '<span>inline: footer</span>'
        . '<span>callable: footer</span>'
        . '<span>class: footer</span>',
    );

    $diagnostics = $registry->diagnostics()[RenderHookLocation::Footer->value];

    expect(array_column($diagnostics, 'registrationType'))->toBe([
        RenderHookRegistrationType::View->value,
        RenderHookRegistrationType::InlineBlade->value,
        RenderHookRegistrationType::Callable->value,
        RenderHookRegistrationType::ExtensionClass->value,
    ]);

    File::deleteDirectory($viewPath);
});

it('keeps legacy string render hook registration as inline blade for compatibility', function (): void {
    $registry = new RenderHookRegistry;

    $registry->register(RenderHookLocation::Footer, '<span>{{ $context->location }}</span>');

    expect($registry->renderAll(RenderHookLocation::Footer))->toBe('<span>footer</span>')
        ->and($registry->diagnostics()[RenderHookLocation::Footer->value][0]['registrationType'])
        ->toBe(RenderHookRegistrationType::LegacyString->value);
});

it('passes mutable main content context through filtered hooks', function (): void {
    $registry = new RenderHookRegistry;
    $contextData = new MainContentRenderHookData(
        layout: (object) ['containers' => []],
        page: null,
        pageSlot: '<p>Fallback</p>',
    );

    $registry->register(
        RenderHookLocation::MainContent,
        function (RenderHookContext $context): string {
            expect($context->item)->toBeInstanceOf(MainContentRenderHookData::class);

            $context->item->slotRendered = true;
            $context->item->pageContentWidgetRendered = true;

            return '<section>Hooked main content</section>';
        },
        scenario: 'frontend-main-layout',
        target: 'capell::layout.main',
    );

    $output = $registry->renderAll(
        RenderHookLocation::MainContent,
        $contextData,
        scenario: 'frontend-main-layout',
        target: 'capell::layout.main',
    );

    expect($output)->toBe('<section>Hooked main content</section>')
        ->and($contextData->slotRendered)->toBeTrue()
        ->and($contextData->pageContentWidgetRendered)->toBeTrue()
        ->and($registry->renderAll(RenderHookLocation::MainContent, $contextData))->toBe('');
});

it('interleaves keyed hooks with relative positions and reports collisions', function (): void {
    $registry = new RenderHookRegistry;
    $registry->contribute(RenderHookContributionData::inlineBlade(
        RenderHookLocation::Footer,
        '<span>a</span>',
        'vendor/a',
        'a',
        position: ExtensionPosition::priority(10),
    ));
    $registry->contribute(RenderHookContributionData::inlineBlade(
        RenderHookLocation::Footer,
        '<span>b</span>',
        'vendor/b',
        'b',
        position: ExtensionPosition::before('a'),
    ));

    expect($registry->renderAll(RenderHookLocation::Footer))->toBe('<span>b</span><span>a</span>');
    expect(function () use ($registry): void {
        $registry->contribute(RenderHookContributionData::inlineBlade(
            RenderHookLocation::Footer,
            '<span>collision</span>',
            'vendor/c',
            'a',
        ));
    })->toThrow(LogicException::class, 'vendor/a');
});

it('supports explicit replacement and frozen render hooks', function (): void {
    $registry = new RenderHookRegistry;
    $registry->contribute(RenderHookContributionData::inlineBlade(RenderHookLocation::Footer, '<span>old</span>', 'vendor/a', 'item'));
    $registry->replaceContribution(RenderHookContributionData::inlineBlade(RenderHookLocation::Footer, '<span>new</span>', 'vendor/a', 'item'));

    expect($registry->renderAll(RenderHookLocation::Footer))->toBe('<span>new</span>');

    $registry->freeze();
    expect(function () use ($registry): void {
        $registry->contribute(RenderHookContributionData::inlineBlade(RenderHookLocation::Footer, '<span>late</span>', 'vendor/b', 'late'));
    })
        ->toThrow(LogicException::class, 'frozen');
});
