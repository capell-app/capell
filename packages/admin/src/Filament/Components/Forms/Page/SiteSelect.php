<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms\Page;

use Capell\Admin\Filament\Components\Forms\SiteSelect as BaseSiteSelect;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Str;

class SiteSelect extends BaseSiteSelect
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->required()
            ->helperText(__('capell-admin::generic.page_site_info'))
            ->reactive()
            ->default(function (): ?int {
                /** @var class-string<Site> $model */
                $model = Site::class;

                return $model::getDefault()?->id;
            })
            ->hiddenOn(['edit', 'editOption'])
            ->afterStateUpdated(function (Get $get, Set $set, ?int $state): void {
                if ($state === null || $state === 0) {
                    return;
                }

                /** @var class-string<Site> $model */
                $model = Site::class;

                /** @var Site $site */
                $site = $model::with('languages')->find($state);

                $name = $get('name');

                $rawTranslations = $get('translations');
                $translations = collect(is_array($rawTranslations) ? $rawTranslations : [])
                    ->filter(
                        fn (array $translation): bool => $translation['language_id'] !== $site->language_id
                            || $site->languages->contains('id', $translation['language_id']),
                    );

                $addTranslation = function (int $languageId) use (&$translations, $name): void {
                    if ($translations->contains('language_id', $languageId)) {
                        return;
                    }

                    $translations->put(
                        (string) Str::uuid(),
                        [
                            'language_id' => $languageId,
                            'title' => is_string($name) ? $name : '',
                            'meta' => ['slug' => is_string($name) ? Str::slug($name) : ''],
                        ],
                    );
                };

                $addTranslation($site->language_id);

                $site->languages->each(function (Language $language) use ($addTranslation): void {
                    $addTranslation($language->id);
                });

                $set('translations', $translations->toArray());
            });
    }

    public static function getDefaultName(): ?string
    {
        return 'site_id';
    }
}
