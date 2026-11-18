<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Enums\ContentStructure;
use Capell\Frontend\Data\MainContentRenderHookData;
use Capell\Frontend\Data\PublicMainLayoutData;
use Capell\Frontend\Enums\RenderHookLocation;
use Capell\Frontend\Support\Render\PublicRenderDataScope;
use Capell\Frontend\Support\Render\RenderHookRegistry;
use Capell\Frontend\Support\View\PublicPageRelations;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Blade;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildPublicMainLayoutDataAction
{
    use AsFake;
    use AsObject;

    public function __construct(private readonly RenderHookRegistry $hooks) {}

    public function handle(
        mixed $layout,
        ?object $page,
        mixed $pageSlot,
        mixed $theme,
        ?string $containerClass,
        ?string $mainClass,
        ?string $mainContainerClass,
    ): PublicMainLayoutData {
        $themeData = is_array($theme) ? $theme : [];
        $classes = Arr::toCssClasses([
            $themeData['meta']['main_class'] ?? 'py-6 lg:py-10',
            GetLayoutContainerWidthAction::run()->getContainerClass(),
            $mainClass ?? '',
        ]);
        $context = new MainContentRenderHookData(
            layout: $layout,
            page: $page,
            pageSlot: $pageSlot,
            theme: $themeData,
            containerClass: $containerClass,
            mainClass: $mainClass,
            mainContainerClass: $mainContainerClass,
            publicRenderData: PublicRenderDataScope::current(),
        );
        $content = $this->hooks->renderAll(RenderHookLocation::MainContent, $context, 'frontend-main-layout', 'capell::layout.main');

        if ($content === '') {
            $translation = PublicPageRelations::loaded($page, 'translation');
            $blueprint = PublicPageRelations::loaded($page, 'blueprint');
            $content = Blade::render(
                '<x-capell::content :content="$content" :content-type="$type" :title="$title" heading-tag="h1" />',
                [
                    'content' => $translation->content ?? '',
                    'type' => $blueprint->content_structure ?? ContentStructure::Html,
                    'title' => $translation->title ?? '',
                ],
            );
        }

        return new PublicMainLayoutData(
            mainClass: $classes,
            contentHtml: $content,
            slotHtml: $pageSlot && ! $context->slotRendered ? e($pageSlot) : '',
            afterContentHtml: $this->hooks->renderAll(RenderHookLocation::AfterContent, $context, 'frontend-main-layout', 'capell::layout.main'),
        );
    }
}
