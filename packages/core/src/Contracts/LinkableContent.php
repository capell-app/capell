<?php

declare(strict_types=1);

namespace Capell\Core\Contracts;

use Capell\Core\Data\LinkableContentData;
use Illuminate\Support\Collection;

interface LinkableContent
{
    public function key(): string;

    /**
     * @return Collection<int, LinkableContentData>
     */
    public function options(?int $siteId = null, ?int $languageId = null): Collection;
}
