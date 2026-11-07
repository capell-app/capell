<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support\Fixtures\Autoload;

use Capell\Core\Contracts\LinkableContent;
use Illuminate\Support\Collection;

final class TestRegistryLinkableContent implements LinkableContent
{
    public function key(): string
    {
        return 'tests.registry';
    }

    public function options(?int $siteId = null, ?int $languageId = null): Collection
    {
        return collect();
    }
}
