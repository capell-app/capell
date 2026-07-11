<?php

declare(strict_types=1);

use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Marketplace\Filament\Actions\MarketplaceConnectionFormModel;
use Capell\Marketplace\Filament\Extenders\MarketplaceExtensionsPageExtender;
use Capell\Marketplace\Filament\Support\MarketplaceBrowser;
use Illuminate\Http\Request;

it('renders marketplace connection status without extension page authoring links', function (): void {
    config(['capell-marketplace.marketplace.base_url' => null]);

    $content = view('capell-marketplace::filament.pages.extensions-page-marketplace-status', [
        'marketplaceConnection' => resolve(MarketplaceConnectionFormModel::class),
    ])->render();

    expect($content)
        ->toContain(__('capell-marketplace::marketplace.marketplace.status_badge'))
        ->toContain(__('capell-marketplace::marketplace.marketplace.status.needs_configuration.label'))
        ->not->toContain('signed-editor-url')
        ->not->toContain('editable-marker');
});

it('does not queue marketplace catalogue warming during Livewire update renders', function (): void {
    $browser = new class
    {
        public bool $queued = false;

        public function queueDefaultWarm(): bool
        {
            $this->queued = true;

            return true;
        }
    };

    app()->instance(MarketplaceBrowser::class, $browser);
    app()->instance('request', Request::create('/livewire/update', Symfony\Component\HttpFoundation\Request::METHOD_POST));

    expect(resolve(MarketplaceExtensionsPageExtender::class)->getBeforeTableContent(resolve(ExtensionsPage::class)))
        ->toBe([])
        ->and($browser->queued)
        ->toBeFalse();
});
