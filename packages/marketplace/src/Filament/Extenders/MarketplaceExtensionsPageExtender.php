<?php

declare(strict_types=1);

namespace Capell\Marketplace\Filament\Extenders;

use Capell\Admin\Contracts\Extenders\ExtensionsPageExtender;
use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Marketplace\Filament\Support\MarketplaceCatalogueRecordProvider;
use Illuminate\Contracts\Support\Htmlable;

final class MarketplaceExtensionsPageExtender implements ExtensionsPageExtender
{
    /** @return array<int, Htmlable|string> */
    public function getBeforeTableContent(ExtensionsPage $page): array
    {
        if ($this->isLivewireUpdateRequest()) {
            return [];
        }

        resolve(MarketplaceCatalogueRecordProvider::class)->queueDefaultWarm();

        return [];
    }

    private function isLivewireUpdateRequest(): bool
    {
        if (request()->is('livewire/*')) {
            return true;
        }

        return request()->hasHeader('X-Livewire');
    }
}
