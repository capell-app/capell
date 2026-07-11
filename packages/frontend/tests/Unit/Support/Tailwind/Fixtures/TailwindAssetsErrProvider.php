<?php

declare(strict_types=1);

namespace Capell\Frontend\Tests\Unit\Support\Tailwind\Fixtures;

use Capell\Core\Contracts\RegistersTailwindAssets;
use Capell\Core\Support\Tailwind\TailwindAssetsRegistry;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class TailwindAssetsErrProvider extends ServiceProvider implements RegistersTailwindAssets
{
    public function registerTailwindAssets(TailwindAssetsRegistry $registry): void
    {
        throw new RuntimeException('boom');
    }
}
