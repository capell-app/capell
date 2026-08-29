<?php

declare(strict_types=1);

namespace Capell\Frontend\Tests\Fixtures;

use Capell\Frontend\Contracts\FrontendComponentContributor;
use Capell\Frontend\Data\FrontendComponentContributionData;
use Capell\Frontend\Enums\FrontendComponentTarget;

final class LayoutBuilderFrontendComponentContributor implements FrontendComponentContributor
{
    public function components(): array
    {
        return [new FrontendComponentContributionData(
            name: 'layout-builder-contributed',
            component: 'layout-builder::components.contributed',
            target: FrontendComponentTarget::Blade,
        )];
    }
}
