<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Blade;

use Capell\Frontend\Support\Assets\PublicFrontendAssetUrl;

final class FrontendAssetDirective
{
    public function compile(string $expression): string
    {
        return '<?php echo e(app(' . PublicFrontendAssetUrl::class . '::class)->to(' . $expression . ')); ?>';
    }
}
