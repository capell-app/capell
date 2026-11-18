<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Contracts\Media\MediaContract;
use Capell\Frontend\Contracts\FrontendContextReader;
use Capell\Frontend\Data\PublicAssetTileData;
use Capell\Frontend\Enums\RenderHookLocation;
use Capell\Frontend\Support\Render\RenderHookRegistry;
use Capell\Frontend\Support\View\PublicModelMeta;
use Capell\Frontend\Support\View\PublicPageRelations;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Blade;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildPublicAssetTileDataAction
{
    use AsFake;
    use AsObject;

    public function __construct(private readonly FrontendContextReader $frontend, private readonly RenderHookRegistry $hooks) {}

    public function handle(
        ?string $title,
        string $headingSize,
        ?string $summary,
        ?string $url,
        ?string $linkText,
        ?string $icon,
        ?string $size,
        bool $titleBalance,
        ?object $parent,
        ?object $author,
        ?CarbonInterface $publishDate,
        string $publishDatePosition,
        MediaContract|string|null $image,
        string $imagePosition,
        bool $squareImage,
        mixed $item,
    ): PublicAssetTileData {
        $rounded = (bool) PublicModelMeta::get($this->frontend->theme(), 'rounded_images', false);
        $onTop = $imagePosition === 'top';
        $title ??= '';
        $renderedImage = null;
        if ($image !== null) {
            $class = implode(' ', array_filter([
                'asset-tile-image w-full overflow-hidden object-cover object-center',
                $squareImage ? 'aspect-square' : ($onTop ? 'h-56' : 'h-48'),
                $rounded ? 'rounded' : '',
            ]));
            $height = $onTop ? 224 : 192;
            $renderedImage = Blade::render('<x-capell::media :media="$image" :alt="$title" fit="contain" :height="$height" loading="lazy" :class="$class" />', ['image' => $image, 'title' => $title, 'height' => $height, 'class' => $class]);
        }

        $translation = PublicPageRelations::loaded($parent, 'translation');

        $parentLabel = $translation->label ?? null;
        $dated = $publishDate instanceof CarbonInterface && in_array($publishDatePosition, ['top', 'bottom'], true);

        return new PublicAssetTileData(
            title: $title,
            headingSize: $headingSize,
            summary: $summary ?? '',
            url: $url ?? '',
            linkText: $linkText ?? '',
            icon: $icon ?? '',
            size: $size,
            titleBalance: $titleBalance,
            roundedImages: $rounded,
            imageOnTop: $onTop,
            imageOnRight: $imagePosition === 'right',
            renderedImage: $renderedImage,
            hasParent: $parent !== null,
            parentUrl: $parentLabel ? PublicPageRelations::fullUrl($parent) : null,
            parentLabel: $parentLabel,
            date: $dated ? $publishDate->format(config('capell-frontend.date_format')) : null,
            dateTime: $dated ? $publishDate->toW3cString() : null,
            datePosition: $publishDatePosition,
            authorName: $dated && $author !== null ? (string) ($author->name ?? '') : null,
            beforeContent: $this->hooks->renderAll(RenderHookLocation::BeforeContent, ['item' => $item]),
            beforeTitle: $this->hooks->renderAll(RenderHookLocation::BeforeTitle, $item),
            afterTitle: $this->hooks->renderAll(RenderHookLocation::AfterTitle, $item),
            footer: $this->hooks->renderAll(RenderHookLocation::Footer, $item),
        );
    }
}
