<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Filament\Plugin;

use Livewire\Component;

final class CachedAssetComponent extends Component
{
    public function styleModuleSrc(): string
    {
        return __DIR__ . '/cached-asset.css';
    }

    public function globalStyleModuleSrc(): string
    {
        return $this->styleModuleSrc();
    }

    public function scriptModuleSrc(): string
    {
        return __DIR__ . '/cached-asset.js';
    }
}
