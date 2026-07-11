<?php

declare(strict_types=1);

use Capell\Frontend\Contracts\RenderHookExtensionInterface;
use Capell\Frontend\Data\MainContentRenderHookData;
use Capell\Frontend\Data\RenderHookContext;
use Capell\Frontend\Data\RenderHookContributionData;
use Capell\Frontend\Enums\RenderHookLocation;
use Capell\Frontend\Enums\RenderHookRegistrationType;
use Capell\Frontend\Support\Render\RenderHookRegistry;
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
