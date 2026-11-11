<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\SiteSpec;

use Capell\Core\Data\SiteSpec\SiteSpecPresetData;

/**
 * Lets a package or theme offer its own site presets next to Core's.
 *
 * @api
 */
interface SiteSpecPresetProvider
{
    public const string TAG = 'capell.site-spec.preset-provider';

    /** @return list<SiteSpecPresetData> */
    public function presets(): array;
}
