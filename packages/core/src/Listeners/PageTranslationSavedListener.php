<?php

declare(strict_types=1);

namespace Capell\Core\Listeners;

use Capell\Core\Actions\ContentGraph\RebuildContentGraphForModelAction;
use Capell\Core\Actions\SetupPageUrlsAction;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Enums\CacheEnum;
use Capell\Core\Models\Translation;
use Capell\Core\Support\CapellCoreHelper;
use Illuminate\Database\Eloquent\Model;

final class PageTranslationSavedListener
{
    public function __invoke(Translation $translation): void
    {
        if (! $translation->isPage()) {
            return;
        }

        /** @var Pageable<Model>&Model $page */
        $page = $translation->translatable()->first();
        if (! $page instanceof Model || ! $page instanceof Pageable) {
            return;
        }

        SetupPageUrlsAction::run($page);

        CapellCoreHelper::flushCache([
            CacheEnum::SiteLanguages->value,
            CacheEnum::FirstPageByTypeForSite->value,
            CacheEnum::RelationExists->value,
        ]);

        defer(
            function () use ($page): void {
                $freshPage = $page->newQuery()->find($page->getKey());

                if ($freshPage instanceof Model) {
                    RebuildContentGraphForModelAction::run($freshPage);
                }
            },
            'content-graph:' . $page::class . ':' . $page->getKey(),
            always: true,
        );
    }
}
