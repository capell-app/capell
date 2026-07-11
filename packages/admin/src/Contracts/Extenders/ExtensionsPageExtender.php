<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Extenders;

use Capell\Admin\Filament\Pages\ExtensionsPage;
use Illuminate\Contracts\Support\Htmlable;

interface ExtensionsPageExtender
{
    public const string TAG = 'capell-admin:extensions-page-extender';

    /** @return array<int, Htmlable|string> */
    public function getBeforeTableContent(ExtensionsPage $page): array;
}
