<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Extensions;

interface ExtensionTableDataSource
{
    /**
     * @param  array<string, string|null>  $filters
     * @return list<array<string, mixed>>
     */
    public function getExtensionsData(?string $search = null, ?string $productGroup = null, array $filters = []): array;
}
