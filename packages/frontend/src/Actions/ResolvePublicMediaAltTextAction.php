<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Contracts\Media\MediaContract;
use Capell\Core\Models\Media;
use Capell\Frontend\Facades\Frontend;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class ResolvePublicMediaAltTextAction
{
    use AsFake;
    use AsObject;

    public function handle(MediaContract $media): string
    {
        return ($media instanceof Media ? $media->getAltText(Frontend::language()) : null) ?? $media->getName();
    }
}
