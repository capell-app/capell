<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms;

use Capell\Core\Enums\CacheFrequency;
use Filament\Forms\Components\Select;
use Override;

class CacheFrequencySelect extends Select
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('capell-admin::form.cache_frequency'))
            ->extraFieldWrapperAttributes([
                'class' => 'fi-fo-field-stack-hint-icon',
            ])
            ->hintIcon('heroicon-o-information-circle', tooltip: __('capell-admin::generic.cache_frequency_info'))
            ->options(CacheFrequency::class);
    }
}
