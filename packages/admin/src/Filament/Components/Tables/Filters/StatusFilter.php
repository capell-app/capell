<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Tables\Filters;

use Filament\Tables\Filters\TernaryFilter;
use Override;

class StatusFilter extends TernaryFilter
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('capell-admin::form.status'))
            ->trueLabel(__('capell-admin::form.enabled'))
            ->falseLabel(__('capell-admin::form.disabled'));
    }
}
