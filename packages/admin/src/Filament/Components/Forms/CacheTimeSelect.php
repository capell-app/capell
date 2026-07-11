<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms;

use Capell\Core\Enums\CacheTime;
use Filament\Forms\Components\Select;
use Override;

class CacheTimeSelect extends Select
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('capell-admin::form.cache_time'))
            ->helperText(__('capell-admin::generic.cache_time_info'))
            ->options(CacheTime::class);
    }
}
