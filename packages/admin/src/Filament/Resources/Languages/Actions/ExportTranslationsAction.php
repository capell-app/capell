<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Languages\Actions;

use Capell\Admin\Actions\Translations\ExportSiteTranslationsAction;
use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Support\SiteScope;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Support\Icons\Heroicon;
use Override;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportTranslationsAction extends Action
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('capell-admin::exchanger.export_translations.label'))
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->modalDescription(__('capell-admin::exchanger.export_translations.description'))
            ->modalSubmitActionLabel(__('capell-admin::exchanger.export_translations.submit'))
            ->visible(self::actorCanExport(...))
            ->schema([
                Select::make('site_id')
                    ->label(__('capell-admin::form.site'))
                    ->options(self::getSiteOptions(...))
                    ->searchable()
                    ->required(),
                Select::make('language_id')
                    ->label(__('capell-admin::table.language'))
                    ->options(self::getLanguageOptions(...))
                    ->placeholder(__('capell-admin::exchanger.export_translations.all_languages'))
                    ->searchable(),
            ])
            ->action(self::export(...));
    }

    public static function getDefaultName(): ?string
    {
        return 'export-translations';
    }

    /**
     * Deliberately not named isVisible(): Filament\Actions\Action declares that
     * as an instance method, and redeclaring it static is a load-time fatal.
     */
    protected static function actorCanExport(): bool
    {
        return auth()->user()?->can(ResourceEnum::Page->permission('view_any')) === true;
    }

    /**
     * @return array<int, string>
     */
    protected static function getSiteOptions(): array
    {
        /** @var array<int, string> $options */
        $options = SiteScope::applyForCurrentActor(Site::query(), 'id')
            ->ordered()
            ->pluck('name', 'id')
            ->all();

        return $options;
    }

    /**
     * @return array<int, string>
     */
    protected static function getLanguageOptions(): array
    {
        /** @var class-string<Language> $model */
        $model = Language::class;

        /** @var array<int, string> $options */
        $options = $model::query()
            ->ordered()
            ->pluck('name', 'id')
            ->all();

        return $options;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected static function export(array $data): ?StreamedResponse
    {
        /** @var Site|null $site */
        $site = Site::query()->find($data['site_id'] ?? null);

        if (! $site instanceof Site) {
            return null;
        }

        $languageId = $data['language_id'] ?? null;

        /** @var Language|null $language */
        $language = $languageId === null || $languageId === ''
            ? null
            : Language::query()->find($languageId);

        return ExportSiteTranslationsAction::run($site, $language);
    }
}
