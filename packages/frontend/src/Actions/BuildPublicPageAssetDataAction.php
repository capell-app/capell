<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Actions\ResolveRenderableComponentAction;
use Capell\Core\Enums\RenderableTypeEnum;
use Capell\Frontend\Contracts\FrontendContextReader;
use Capell\Frontend\Data\PublicPageAssetData;
use Capell\Frontend\Support\View\PublicModelMeta;
use Capell\Frontend\Support\View\PublicPageRelations;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\ComponentAttributeBag;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildPublicPageAssetDataAction
{
    use AsFake;
    use AsObject;

    public function __construct(private readonly FrontendContextReader $frontend) {}

    public function handle(
        object $asset,
        string $componentItem,
        mixed $loop,
        ComponentAttributeBag $attributes,
        ?string $size,
        bool $withAuthor,
        bool $withChildCount,
        bool $withLinkText,
        bool $withImage,
        bool $withParent,
        bool $withDate,
        bool $withSummary,
        bool $withUrl,
        ?string $headingSize,
        string $imagePosition,
    ): PublicPageAssetData {
        $translation = PublicPageRelations::loaded($asset, 'translation');
        $parent = $withParent ? PublicPageRelations::loaded($asset, 'parent') : null;
        $ancestor = $withParent ? PublicPageRelations::loaded($asset, 'ancestors') : null;
        if ($ancestor instanceof Collection && $ancestor->count() === 1 && $ancestor->first()?->getAttribute('id') === $this->frontend->page()?->id) {
            $ancestor = false;
        }

        $image = null;
        if ($withImage && method_exists($asset, 'relationLoaded')) {
            $image = PublicPageRelations::image($asset) ?? PublicModelMeta::get($asset, 'image_source');
        }

        $componentItem = ResolveRenderableComponentAction::run(RenderableTypeEnum::Asset, $componentItem);
        $author = $withAuthor ? PublicPageRelations::loaded($asset, 'creator') : null;
        $count = $withChildCount ? ($asset->children_count ?? null) : null;
        $linkText = $withLinkText ? PublicModelMeta::get($translation, 'link_text', __('capell-frontend::generic.read_more')) : null;
        $publishDate = $withDate && method_exists($asset, 'getPublishDate') ? $asset->getPublishDate() : null;
        $summary = $withSummary ? ($translation->summary ?? null) : null;
        $title = $translation->label ?? null;
        $url = $withUrl ? PublicPageRelations::fullUrl($asset) : null;

        return new PublicPageAssetData(Blade::render(<<<'BLADE'
            <x-dynamic-component
                :component="$componentItem" :$asset :$loop :$size :$ancestor :$author :$count :$image
                :heading-size="$headingSize" :image-position="$imagePosition" :link-text="$linkText"
                :$parent :publish-date="$publishDate" :$summary :$title :$url
                :attributes="$attributes->merge(['class' => 'capell-component capell-page-asset page-asset'])"
            />
            BLADE, ['componentItem' => $componentItem, 'asset' => $asset, 'loop' => $loop, 'size' => $size, 'ancestor' => $ancestor, 'author' => $author, 'count' => $count, 'image' => $image, 'headingSize' => $headingSize, 'imagePosition' => $imagePosition, 'linkText' => $linkText, 'parent' => $parent, 'publishDate' => $publishDate, 'summary' => $summary, 'title' => $title, 'url' => $url, 'attributes' => $attributes]));
    }
}
