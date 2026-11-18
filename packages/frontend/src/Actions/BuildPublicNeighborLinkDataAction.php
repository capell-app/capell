<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Contracts\Media\MediaContract;
use Capell\Frontend\Contracts\FrontendContextReader;
use Capell\Frontend\Data\PublicNeighborLinkData;
use Capell\Frontend\Support\View\PublicModelMeta;
use Capell\Frontend\Support\View\PublicPageRelations;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Blade;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildPublicNeighborLinkDataAction
{
    use AsFake;
    use AsObject;

    public function __construct(private readonly FrontendContextReader $frontend) {}

    public function handle(?object $neighborPage, ?string $neighbor, bool $withSummary, bool $withImage): PublicNeighborLinkData
    {
        $translation = PublicPageRelations::loaded($neighborPage, 'translation');
        $image = PublicPageRelations::loaded($neighborPage, 'image');
        $url = PublicPageRelations::fullUrl($neighborPage);
        $title = $translation->title ?? '';
        $renderedImage = null;
        if ($url && $withImage && $image instanceof MediaContract && in_array($neighbor, ['previous', 'next'], true)) {
            $class = Arr::toCssClasses([
                'h-full w-auto max-w-[5rem] object-cover object-center group-hover:opacity-85 group-focus:opacity-85',
                'rounded' => PublicModelMeta::get($this->frontend->theme(), 'rounded_images', false),
            ]);
            $renderedImage = Blade::render('<x-capell::media :media="$image" :alt="$title" fit="crop" :width="200" :class="$class" loading="lazy" />', ['image' => $image, 'title' => $title, 'class' => $class]);
        }

        return new PublicNeighborLinkData($url, $neighbor, $title, $translation->label ?? $title, $withSummary ? ($translation->summary ?? null) : null, $renderedImage);
    }
}
