<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsObject;

class SetupPageUrlsAction
{
    use AsObject;

    /**
     * @template TDeclaringModel of Model
     *
     * @param  Pageable<TDeclaringModel>  $page
     */
    public function handle(Pageable $page, bool $updateDescendants = true): void
    {
        $page->load($this->getRelations());

        $this->updateTranslations($page, $page->site);

        if ($updateDescendants) {
            $this->updateDescendantsUrls($page, $page->site);
        }
    }

    /**
     * @return list<string>
     */
    private function getRelations(): array
    {
        return [
            'translations:translatable_id,translatable_type,language_id,meta',
            'translations.language',
        ];
    }

    /**
     * @template TDeclaringModel of Model
     *
     * @param  Pageable<TDeclaringModel>  $page
     */
    private function updateTranslations(Pageable $page, Site $site): void
    {
        $page->translations->each(function (Translation $translation) use ($page, $site): void {
            $url = $page->getParentUrl($translation->language);

            UpdatePageUrlAction::run($site, $translation, $url);
        });
    }

    /**
     * @template TDeclaringModel of Model
     *
     * @param  Pageable<TDeclaringModel>  $page
     */
    private function updateDescendantsUrls(Pageable $page, Site $site): void
    {
        $descendants = $page->descendants()->get();
        $descendants->load($this->getRelations());

        $descendants->each(function (Model $descendant) use ($site): void {
            if (! $descendant instanceof Pageable) {
                return;
            }

            $descendant->translations->each(function (Translation $translation) use ($site, $descendant): void {
                $url = $descendant->getParentUrl(language: $translation->language);

                UpdatePageUrlAction::run($site, $translation, $url);
            });
        });
    }
}
