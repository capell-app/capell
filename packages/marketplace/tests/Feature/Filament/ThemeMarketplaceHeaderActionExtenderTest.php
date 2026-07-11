<?php

declare(strict_types=1);

use Capell\Admin\Contracts\Extenders\ResourceHeaderActionExtender;
use Capell\Admin\Filament\Resources\Themes\Pages\ManageThemes;
use Capell\Marketplace\Filament\Extenders\ThemeMarketplaceHeaderActionExtender;
use Illuminate\Support\Facades\Http;

it('supports only the theme management page', function (): void {
    $extender = new ThemeMarketplaceHeaderActionExtender;

    expect($extender)->toBeInstanceOf(ResourceHeaderActionExtender::class)
        ->and($extender->supports(ManageThemes::class))->toBeTrue()
        ->and($extender->supports('App\\Filament\\Pages\\OtherPage'))->toBeFalse();
});

it('provides an install theme header action', function (): void {
    $actions = (new ThemeMarketplaceHeaderActionExtender)->actions();

    expect($actions)->toHaveCount(1)
        ->and($actions[0]->getName())->toBe('installMarketplaceTheme')
        ->and($actions[0]->getLabel())->toBe(__('capell-marketplace::marketplace.themes.install_button'))
        ->and($actions[0]->getModalHeading())->toBe(__('capell-marketplace::marketplace.themes.install_heading'))
        ->and($actions[0]->getModalDescription())->toBe(__('capell-marketplace::marketplace.themes.install_description'));
});

it('opens the marketplace browser with a locked theme kind', function (): void {
    config(['capell-marketplace.marketplace.base_url' => 'https://marketplace.test/api']);

    Http::fake([
        'https://marketplace.test/api/extensions*' => Http::response([
            'data' => [],
            'links' => ['next' => null],
        ]),
    ]);

    $html = view('capell-marketplace::filament.actions.theme-marketplace-browser')->render();

    expect($html)->toContain('marketplace-extensions-browser')
        ->and($html)->toContain('&quot;lockedKind&quot;:&quot;theme&quot;')
        ->and($html)->not->toContain(__('capell-marketplace::marketplace.themes.install_heading'))
        ->and($html)->not->toContain(__('capell-marketplace::marketplace.themes.install_description'));
});
