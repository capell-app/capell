<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Pages;

use Capell\Admin\Data\Pages\PageSiteTranslationsData;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Core\Support\Permissions\SiteAccess;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static PageSiteTranslationsData run(int $siteId, array<int|string, mixed> $translations, string $name = '', bool $removeUnsupported = false)
 */
final class ReconcilePageSiteTranslationsAction
{
    use AsFake;
    use AsObject;

    /** @param array<int|string, mixed> $translations */
    public function handle(int $siteId, array $translations, string $name = '', bool $removeUnsupported = false): PageSiteTranslationsData
    {
        /** @var Site $site */
        $site = SiteAccess::current()->query(Site::class)->with('languages')->findOrFail($siteId);
        $languageIds = $site->languages->toBase()->map(fn (Language $language): int => $language->id)
            ->prepend($site->language_id)->unique()->values()->all();
        $unsupportedIds = [];
        $presentIds = [];

        foreach ($translations as $key => $translation) {
            if (! is_array($translation)) {
                continue;
            }

            // Form hydration adds null placeholders for metadata the author has not entered.
            if (is_array($translation['meta'] ?? null)) {
                $translation['meta'] = array_filter($translation['meta'], fn (mixed $value): bool => $value !== null);
                $translations[$key] = $translation;
            }

            $languageId = (int) ($translation['language_id'] ?? 0);
            $presentIds[] = $languageId;

            if (! in_array($languageId, $languageIds, true)) {
                $unsupportedIds[] = $languageId;

                if ($removeUnsupported) {
                    unset($translations[$key]);
                }
            }
        }

        foreach ($languageIds as $languageId) {
            if (! in_array($languageId, $presentIds, true)) {
                $translations[(string) Str::uuid()] = [
                    'language_id' => $languageId,
                    'title' => $name,
                    'meta' => ['slug' => Str::slug($name)],
                ];
            }
        }

        return new PageSiteTranslationsData(
            translations: $translations,
            unsupportedLanguageIds: $removeUnsupported ? [] : array_values(array_unique($unsupportedIds)),
            unsupportedLanguageNames: $removeUnsupported ? [] : array_values(Language::query()->whereKey($unsupportedIds)->get(['name'])
                ->map(fn (Language $language): string => $language->name)->all()),
        );
    }
}
