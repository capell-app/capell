<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Actions\ResolveRenderableComponentAction;
use Capell\Core\Enums\RenderableTypeEnum;
use Capell\Frontend\Data\PublicPageResultsContentData;
use Capell\Frontend\Enums\RenderHookLocation;
use Capell\Frontend\Support\Render\RenderHookRegistry;
use Capell\Frontend\Support\View\PublicModelMeta;
use Capell\Frontend\Support\View\PublicPageRelations;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Blade;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildPublicPageResultsContentDataAction
{
    use AsFake;
    use AsObject;

    public function __construct(private readonly RenderHookRegistry $hooks) {}

    public function handle(?object $results, string $componentItem, ?string $noResultsText, bool $wireLinks, bool $withParent, bool $wireChildrenCount): PublicPageResultsContentData
    {
        $componentItem = ResolveRenderableComponentAction::run(RenderableTypeEnum::Asset, $componentItem);
        $count = $results ? (method_exists($results, 'total') ? $results->total() : (method_exists($results, 'count') ? $results->count() : 0)) : 0;
        if ($results === null) {
            $empty = true;
        } elseif (method_exists($results, 'isEmpty')) {
            $empty = $results->isEmpty();
        } else {
            throw new InvalidArgumentException('Page results must provide an isEmpty method.');
        }

        $renderItem = fn (object $item, mixed $loop): string => $this->renderItem($item, $loop, $componentItem, $withParent, $wireChildrenCount);
        $renderedItems = $empty ? '' : Blade::render(<<<'BLADE'
            @foreach ($results as $item)
                {!! $renderItem($item, $loop) !!}
            @endforeach
            BLADE, ['results' => $results, 'renderItem' => $renderItem]);
        $renderedNoResults = $empty ? Blade::render('<x-capell::no-results>{{ $content }}</x-capell::no-results>', ['content' => RenderHtmlContentAction::run((string) $noResultsText)]) : '';
        $renderedPagination = $results instanceof LengthAwarePaginator ? Blade::render(<<<'BLADE'
            <x-capell::pagination :$results :wire-links="$wireLinks" scroll-to-element="#main" class="my-6 mt-10 flex flex-col items-center justify-center gap-6 lg:mt-16 dark:text-white" />
            BLADE, ['results' => $results, 'wireLinks' => $wireLinks]) : '';

        return new PublicPageResultsContentData((int) $count, $empty, $renderedItems, $renderedNoResults, $renderedPagination);
    }

    private function renderItem(object $item, mixed $loop, string $componentItem, bool $withParent, bool $wireChildrenCount): string
    {
        $author = PublicPageRelations::loaded($item, 'creator');
        $image = PublicPageRelations::loaded($item, 'image');
        $parent = $withParent ? PublicPageRelations::loaded($item, 'parent') : null;
        $pageUrl = PublicPageRelations::loaded($item, 'pageUrl');
        $translation = PublicPageRelations::loaded($item, 'translation');
        $squareImage = (bool) PublicModelMeta::get($item, 'square_image', false);
        $publishDatePosition = PublicModelMeta::get($translation, 'publish_date_position', 'top');

        $before = $this->hooks->renderAll(RenderHookLocation::BeforeResult, $item);
        $count = $wireChildrenCount ? ($item->children_count ?? null) : null;
        $publishDate = method_exists($item, 'getPublishDate') ? $item->getPublishDate() : null;
        $summary = $translation->summary ?? null;
        $title = $translation->title ?? null;
        $url = PublicPageRelations::loadedUrl($pageUrl);
        $renderedItem = Blade::render(<<<'BLADE'
            <x-dynamic-component
                :component="$componentItem" :$loop :asset="$item" :$author :$count :$image
                :link-text="__('capell-frontend::generic.read_more')" :$parent :publish-date="$publishDate"
                :$summary :$title :$url :square-image="$squareImage" :with-summary="true" :with-author="true"
                :publish-date-position="$publishDatePosition" role="listitem"
            />
            BLADE, ['componentItem' => $componentItem, 'loop' => $loop, 'item' => $item, 'author' => $author, 'count' => $count, 'image' => $image, 'parent' => $parent, 'publishDate' => $publishDate, 'summary' => $summary, 'title' => $title, 'url' => $url, 'squareImage' => $squareImage, 'publishDatePosition' => $publishDatePosition]);

        return $before . $renderedItem . $this->hooks->renderAll(RenderHookLocation::AfterResult, $item);
    }
}
