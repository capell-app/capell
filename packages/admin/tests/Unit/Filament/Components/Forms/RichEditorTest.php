<?php

declare(strict_types=1);

use Capell\Admin\Filament\Components\Forms\Editor\RichEditor;

it('does not serialize the default Filament text color palette for every editor instance', function (): void {
    expect(RichEditor::make('content')->getTextColorsForJs())->toBe([]);
});
