<?php

declare(strict_types=1);

namespace Capell\Frontend\Contracts;

use Capell\Frontend\Data\FrontendRenderContextData;

interface SchemaGraphContributor
{
    public const string TAG = 'capell-frontend.schema-graph-contributor';

    /** @return list<string>|null */
    public function blueprintKeys(): ?array;

    /** @return list<array<string, mixed>> */
    public function contribute(FrontendRenderContextData $context): array;
}
