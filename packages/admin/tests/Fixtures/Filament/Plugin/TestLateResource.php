<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Filament\Plugin;

use Capell\Core\Models\Site;
use Filament\Resources\Resource;
use Override;

class TestLateResource extends Resource
{
    public static bool $shouldRegisterWithPanel = true;

    #[Override]
    public static function getModel(): string
    {
        return Site::class;
    }

    public static function shouldRegisterWithPanel(): bool
    {
        return self::$shouldRegisterWithPanel;
    }
}
