<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Enums\ContainerWidthEnum;
use Capell\Core\Models\Layout;
use Capell\Frontend\Facades\Frontend;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static ContainerWidthEnum run(?string $default = null, ?Layout $layout = null)
 */
class GetLayoutContainerWidthAction
{
    use AsFake;
    use AsObject;

    public function handle(?string $default = null, ?Layout $layout = null): ContainerWidthEnum
    {
        $layout ??= Frontend::layout();

        $containerClass = $layout instanceof Layout ? $layout->getMeta('container') : null;

        if (filled($containerClass)) {
            return ContainerWidthEnum::from($containerClass);
        }

        $default ??= config('capell-frontend.container_width_default');

        if (filled($default)) {
            return ContainerWidthEnum::from($default);
        }

        return ContainerWidthEnum::ExtraLarge;
    }
}
