<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Pages;

use Capell\Admin\Data\Pages\FrontendSourceMapItemData;
use Capell\Admin\Enums\ResourceEnum as AdminResourceEnum;
use Capell\Admin\Support\AdminSurfaceLookup;
use Capell\Core\Actions\GetEditPageResourceUrlAction;
use Capell\Core\Models\Page;
use Capell\Core\Models\Translation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

/**
 * @method static Collection<int, FrontendSourceMapItemData> run(Page $page)
 */
final class BuildFrontendSourceMapAction
{
    use AsFake;
    use AsObject;

    /**
     * @return Collection<int, FrontendSourceMapItemData>
     */
    public function handle(Page $page): Collection
    {
        $page->loadMissing(['translations.language', 'layout']);

        $items = collect([
            new FrontendSourceMapItemData(
                typeLabel: 'Page',
                preview: $page->name,
                modelReference: $this->modelReference($page),
                fieldPath: 'pages.name',
                editUrl: GetEditPageResourceUrlAction::run($page),
                visible: $this->isVisible($page),
            ),
        ]);

        $this->appendPageTranslations($items, $page);
        $this->appendPageMedia($items, $page);
        $this->appendLayout($items, $page);

        return $items
            ->filter(fn (FrontendSourceMapItemData $item): bool => $item->visible)
            ->values();
    }

    /**
     * @param  Collection<int, FrontendSourceMapItemData>  $items
     */
    private function appendPageTranslations(Collection $items, Page $page): void
    {
        $page->translations->each(function (Translation $translation) use ($items, $page): void {
            $language = $translation->language->code;
            $items->push(new FrontendSourceMapItemData(
                typeLabel: 'Page translation',
                preview: $this->preview((string) $translation->title),
                modelReference: $this->modelReference($translation),
                fieldPath: sprintf('translations.%s.title', $language),
                editUrl: GetEditPageResourceUrlAction::run($page),
                visible: $this->isVisible($page),
            ));

            if ($translation->content !== null && $translation->content !== '') {
                $items->push(new FrontendSourceMapItemData(
                    typeLabel: 'Page content',
                    preview: $this->preview(strip_tags((string) $translation->content)),
                    modelReference: $this->modelReference($translation),
                    fieldPath: sprintf('translations.%s.content', $language),
                    editUrl: GetEditPageResourceUrlAction::run($page),
                    visible: $this->isVisible($page),
                ));
            }

            $metaTitle = data_get($translation->meta, 'meta_title');
            if (is_string($metaTitle) && $metaTitle !== '') {
                $items->push(new FrontendSourceMapItemData(
                    typeLabel: 'SEO title',
                    preview: $this->preview($metaTitle),
                    modelReference: $this->modelReference($translation),
                    fieldPath: sprintf('translations.%s.meta.meta_title', $language),
                    editUrl: GetEditPageResourceUrlAction::run($page),
                    visible: $this->isVisible($page),
                ));
            }
        });
    }

    /**
     * @param  Collection<int, FrontendSourceMapItemData>  $items
     */
    private function appendPageMedia(Collection $items, Page $page): void
    {
        $page->loadMissing('media');

        $page->media->each(function (Model $media) use ($items, $page): void {
            $items->push(new FrontendSourceMapItemData(
                typeLabel: 'Page media',
                preview: (string) ($media->getAttribute('name') ?? $media->getAttribute('file_name') ?? 'Media'),
                modelReference: $this->modelReference($media),
                fieldPath: 'page.media.' . $media->getAttribute('collection_name'),
                editUrl: $this->adminResourceUrl(AdminResourceEnum::Media, $media),
                visible: $this->isVisible($page),
            ));
        });
    }

    /**
     * @param  Collection<int, FrontendSourceMapItemData>  $items
     */
    private function appendLayout(Collection $items, Page $page): void
    {
        $layout = $page->layout;

        $items->push(new FrontendSourceMapItemData(
            typeLabel: 'Layout',
            preview: $layout->name,
            modelReference: $this->modelReference($layout),
            fieldPath: 'pages.layout_id',
            editUrl: $this->adminResourceUrl(AdminResourceEnum::Layout, $layout),
            visible: true,
        ));
    }

    private function adminResourceUrl(AdminResourceEnum $resource, Model $record): ?string
    {
        try {
            $resourceClass = AdminSurfaceLookup::resource($resource);

            return $resourceClass::getUrl('edit', ['record' => $record]);
        } catch (Throwable) {
            return null;
        }
    }

    private function modelReference(Model $model): string
    {
        return sprintf('%s#%s', class_basename($model), (string) $model->getKey());
    }

    private function preview(string $value): string
    {
        return str($value)->squish()->limit(120)->toString();
    }

    private function isVisible(Model $model): bool
    {
        if (method_exists($model, 'isPending') && $model->isPending()) {
            return false;
        }

        if (method_exists($model, 'isExpired') && $model->isExpired()) {
            return false;
        }

        return ! method_exists($model, 'isEnabled') || $model->isEnabled();
    }
}
