<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Filament\Plugin;

use Illuminate\Routing\Controllers\HasMiddleware;
use Override;
use RuntimeException;

final class InspectedRouteController implements HasMiddleware
{
    public static int $inspections = 0;

    public static bool $broken = false;

    public function __invoke(): string
    {
        return 'inspected route served';
    }

    #[Override]
    public static function middleware(): array
    {
        self::$inspections++;
        throw_if(self::$broken, RuntimeException::class, 'Cannot inspect this route controller');

        return [];
    }
}
