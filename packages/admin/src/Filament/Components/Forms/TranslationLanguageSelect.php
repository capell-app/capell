<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms;

use Capell\Admin\Support\Filament\RawState;
use Capell\Admin\Support\SiteScope;
use Capell\Core\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Override;

class TranslationLanguageSelect extends LanguageSelect
{
    protected string $table = 'translations';

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->relationship(
            name: 'language',
            titleAttribute: 'name',
            modifyQueryUsing: function (Builder $query, ?int $state, self $component): Builder {
                $component->modifyQuerySiteLanguages($query);

                return $query->ordered();
            },
        )
            ->required()
            ->reactive()
            ->dehydrated()
            ->dehydratedWhenHidden()
            ->selectablePlaceholder(false)
            ->fixIndistinctState()
            ->disableOptionsWhenSelectedInSiblingRepeaterItems()
            ->autoDefault();
    }

    public static function getDefaultName(): ?string
    {
        return 'language_id';
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    protected function modifyQuerySiteLanguages(Builder $query): Builder
    {
        $rawState = RawState::array($this->getRootContainer()->getRawState());
        $siteId = $rawState['site_id'] ?? null;

        if (! $siteId) {
            return $query;
        }

        /** @var class-string<Site> $model */
        $model = Site::class;

        $site = SiteScope::applyForCurrentActor($model::query(), 'id')
            ->with('siteDomains:id,language_id,site_id')
            ->find($siteId, ['id', 'language_id']);

        if (! $site instanceof Site) {
            return $query->whereRaw('1 = 0');
        }

        $ids = $site->siteDomains->pluck('language_id')->unique()->values()->toArray();

        if (! in_array($site->language_id, $ids, true)) {
            $ids[] = $site->language_id;
        }

        return $query->whereIn('id', $ids);
    }
}
