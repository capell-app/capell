<?php

declare(strict_types=1);

namespace Capell\Frontend\Tests\Unit\Support\Tailwind\Fixtures;

use Capell\Core\Contracts\RegistersTailwindAssets;
use Capell\Core\Support\Tailwind\TailwindAssetsRegistry;
use Illuminate\Support\ServiceProvider;

class TailwindAssetsOkProvider extends ServiceProvider implements RegistersTailwindAssets
{
    public function registerTailwindAssets(TailwindAssetsRegistry $registry): void
    {
        $registry->registerImport('./ok.css')->registerPlugin('@tailwindcss/form-builder')->registerSource('./views/**/*.blade.php');
    }
}
