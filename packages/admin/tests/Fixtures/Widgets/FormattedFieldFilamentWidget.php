<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Widgets;

use Capell\Admin\Contracts\Widgets\FilamentWidget;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\TextInput;
use Override;

/**
 * Stores a bare identifier but shows it as a URL, so a hydrated editor state is
 * distinguishable from raw stored state.
 */
final class FormattedFieldFilamentWidget implements FilamentWidget
{
    public const string STORED = 'abcdefghijk';

    public const string FORMATTED = 'https://example.test/watch?v=abcdefghijk';

    #[Override]
    public static function getWidgetName(): string
    {
        return 'capell-app.formatted-field';
    }

    #[Override]
    public static function make(): Block
    {
        return Block::make(self::getWidgetName())->schema([
            TextInput::make('reference')
                ->formatStateUsing(static fn (mixed $state): mixed => $state === self::STORED ? self::FORMATTED : $state),
        ]);
    }
}
