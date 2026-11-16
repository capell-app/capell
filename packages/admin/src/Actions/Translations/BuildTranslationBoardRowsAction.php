<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Translations;

use Capell\Admin\Data\Translations\TranslationBoardCellData;
use Capell\Admin\Data\Translations\TranslationBoardLanguageData;
use Capell\Admin\Data\Translations\TranslationBoardRowData;
use Capell\Admin\Filament\Resources\Pages\PageResource;
use Capell\Core\Models\Page;
use Capell\Core\Models\Translation;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Turns pages whose translations are already eager-loaded into board rows, one cell per board language.
 *
 * @method static list<TranslationBoardRowData> run(iterable<Page> $pages, list<TranslationBoardLanguageData> $languages, bool $linkToEditor)
 */
final class BuildTranslationBoardRowsAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  iterable<Page>  $pages
     * @param  list<TranslationBoardLanguageData>  $languages
     * @return list<TranslationBoardRowData>
     */
    public function handle(iterable $pages, array $languages, bool $linkToEditor): array
    {
        $defaultLanguageId = null;

        foreach ($languages as $language) {
            if ($language->isDefault) {
                $defaultLanguageId = $language->id;
            }
        }

        $rows = [];

        foreach ($pages as $page) {
            $translations = $this->translationsByLanguage($page);
            $defaultTranslation = $defaultLanguageId === null ? null : $translations->get($defaultLanguageId);

            $cells = [];

            foreach ($languages as $language) {
                $cells[] = new TranslationBoardCellData(
                    languageId: $language->id,
                    status: ResolveTranslationBoardStatusAction::run($translations->get($language->id), $defaultTranslation),
                );
            }

            $title = $defaultTranslation?->getAttribute('title') ?? $translations->first()?->getAttribute('title');

            $rows[] = new TranslationBoardRowData(
                pageId: (int) $page->getKey(),
                title: is_string($title) && $title !== '' ? $title : (string) __('capell-admin::translation_board.untitled_page'),
                editUrl: $linkToEditor ? PageResource::getUrl('edit', ['record' => $page]) : null,
                cells: $cells,
            );
        }

        return $rows;
    }

    /**
     * The page's eager-loaded translations keyed by language id.
     *
     * @return Collection<int, Translation>
     */
    private function translationsByLanguage(Page $page): Collection
    {
        /** @var Collection<int, Translation> $translations */
        $translations = $page->translations->keyBy('language_id');

        return $translations;
    }
}
