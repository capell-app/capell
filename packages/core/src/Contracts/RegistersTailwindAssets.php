<?php

declare(strict_types=1);

namespace Capell\Core\Contracts;

use Capell\Core\Support\Tailwind\TailwindAssetsRegistry;

interface RegistersTailwindAssets
{
    public function registerTailwindAssets(TailwindAssetsRegistry $registry): void;
}
