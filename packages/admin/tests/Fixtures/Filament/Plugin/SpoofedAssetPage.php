<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Filament\Plugin;

use Filament\Pages\BasePage;
use Override;

final class SpoofedAssetPage extends BasePage
{
    public const string BODY = 'spoofed asset page served';

    #[Override]
    public function __invoke(): string
    {
        return self::BODY;
    }
}
