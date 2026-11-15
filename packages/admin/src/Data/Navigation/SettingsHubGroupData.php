<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Navigation;

use Capell\Admin\Enums\NavigationGroupEnum;
use Spatie\LaravelData\Data;

final class SettingsHubGroupData extends Data
{
    /**
     * @param  list<SettingsHubEntryData>  $entries
     */
    public function __construct(
        public readonly NavigationGroupEnum $group,
        public readonly array $entries,
    ) {}
}
