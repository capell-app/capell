<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Frontend\Contracts\FrontendContextReader;
use Capell\Frontend\Support\View\PublicModelMeta;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

/** Whether the active (or given) theme rounds public images. */
final class ResolvePublicRoundedImagesAction
{
    use AsFake;
    use AsObject;

    public function __construct(private readonly FrontendContextReader $frontend) {}

    public function handle(?object $theme = null): bool
    {
        return (bool) PublicModelMeta::get($theme ?? $this->activeTheme(), 'rounded_images', false);
    }

    private function activeTheme(): ?object
    {
        try {
            return $this->frontend->theme();
        } catch (Throwable) {
            return null;
        }
    }
}
