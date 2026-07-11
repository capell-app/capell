<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms;

use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Override;

class ExtraContentSection extends Section
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->heading(__('capell-admin::generic.extra_content'))
            ->icon(Heroicon::OutlinedPuzzlePiece)
            ->collapsed()
            ->columns()
            ->columnSpanFull();
    }
}
