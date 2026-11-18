<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Frontend\Contracts\FrontendContextReader;
use Capell\Frontend\Support\View\PublicModelMeta;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/** Whether the active theme rounds public images. */
final class ResolvePublicRoundedImagesAction
{
    use AsFake;
    use AsObject;

    public function __construct(private readonly FrontendContextReader $frontend) {}

    public function handle(): bool
    {
        return (bool) PublicModelMeta::get($this->frontend->theme(), 'rounded_images', false);
    }
}
