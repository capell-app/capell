<?php

declare(strict_types=1);

namespace Capell\Admin\Data;

use Capell\Admin\Enums\AdminWorkspaceEnum;
use Spatie\LaravelData\Data;

final class AdminWorkspaceStateData extends Data
{
    /**
     * @param  list<string>  $pinnedKeys
     * @param  list<string>  $recentKeys
     */
    public function __construct(
        public readonly AdminWorkspaceEnum $workspace,
        public readonly array $pinnedKeys = [],
        public readonly array $recentKeys = [],
    ) {}
}
