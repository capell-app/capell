<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures;

use Filament\Widgets\Widget;
use Override;

final class WorkFirstMetadataFixtureWidget extends Widget
{
    public static int $visibilityCalls = 0;

    #[Override]
    public static function canView(): bool
    {
        self::$visibilityCalls++;

        return true;
    }
}
