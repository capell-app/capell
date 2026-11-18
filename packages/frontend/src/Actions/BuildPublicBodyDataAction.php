<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Frontend\Data\PublicBodyData;
use Capell\Frontend\Support\View\PublicModelMeta;
use Illuminate\Support\Arr;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildPublicBodyDataAction
{
    use AsFake;
    use AsObject;

    public function handle(object $layout, ?object $theme, ?string $bodyClass = null): PublicBodyData
    {
        $key = data_get($layout, 'key');

        return new PublicBodyData(Arr::toCssClasses([
            'layout-' . (is_scalar($key) ? (string) $key : ''),
            PublicModelMeta::get($layout, 'body_class'),
            PublicModelMeta::get($theme, 'body_class'),
            $bodyClass,
        ]));
    }
}
