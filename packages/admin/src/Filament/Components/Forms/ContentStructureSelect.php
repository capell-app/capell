<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms;

use Capell\Core\Enums\ContentStructure;
use Filament\Forms\Components\Select;
use Override;

class ContentStructureSelect extends Select
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('capell-admin::form.content_structure'))
            ->helperText(__('capell-admin::generic.content_structure_info'))
            ->default(ContentStructure::Html)
            ->options(ContentStructure::class);
    }
}
