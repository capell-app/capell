<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

use Capell\Core\Contracts\SiteSpec\SiteSpecPresetProvider;
use Capell\Core\Data\SiteSpec\SiteSpecPresetData;
use Override;

final class TaggedSiteSpecPresetProvider implements SiteSpecPresetProvider
{
    /** @return list<SiteSpecPresetData> */
    #[Override]
    public function presets(): array
    {
        return [new SiteSpecPresetData(
            key: 'theme-landing',
            label: 'Theme landing page',
            description: 'A single-page preset registered by a theme package.',
            spec: [
                'site' => ['name' => 'Theme site'],
                'theme' => ['key' => 'default'],
                'pages' => [[
                    'name' => 'Home',
                    'slug' => 'home',
                    'title' => 'Welcome',
                    'pageType' => 'page',
                    'sections' => [['type' => 'content', 'content' => '<p>A landing page from a theme.</p>']],
                ]],
            ],
            businessTypes: ['landing'],
        )];
    }
}
