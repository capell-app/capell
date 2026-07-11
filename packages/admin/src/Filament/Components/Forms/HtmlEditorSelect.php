<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms;

use Capell\Admin\Enums\EditorEnum;
use Filament\Forms\Components\Select;
use Override;

class HtmlEditorSelect extends Select
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('capell-admin::form.html_editor'))
            ->default(EditorEnum::RichEditor)
            ->options(EditorEnum::class);
    }
}
