<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms\Editor;

use Override;

class TinyEditor extends \AmidEsfahani\FilamentTinyEditor\TinyEditor
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->minHeight(300);
    }
}
