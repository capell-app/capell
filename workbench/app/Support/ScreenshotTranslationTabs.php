<?php

declare(strict_types=1);

namespace Workbench\App\Support;

use Capell\Admin\Enums\TranslationTabsOrientation;
use Capell\Admin\Filament\Resources\Pages\PageResource;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Screenshot workbench only. The runner cannot change configuration between
 * captures, so a fixture route stores the wanted translation tab orientation
 * in the session and each Filament request applies it. This listens to
 * Filament rather than joining the web group, because the admin panel refuses
 * routes with middleware it does not own. Unknown values are ignored.
 */
final class ScreenshotTranslationTabs
{
    public const string SESSION_KEY = 'screenshot.translation_tabs_orientation';

    /** @var array<string, array{string, string, string, string}> */
    private const array LANGUAGES = [
        'de-shot' => ['de', 'Deutsch', 'Sommerkollektion', '<p>Leichte Schichten für warme Tage.</p>'],
        'fr-shot' => ['fr', 'Français', 'Collection d\'été', '<p>Des couches légères pour les jours chauds.</p>'],
        'es-shot' => ['es', 'Español', 'Colección de verano', '<p>Capas ligeras para los días cálidos.</p>'],
    ];

    public static function apply(): void
    {
        $orientation = session(self::SESSION_KEY);

        if (is_string($orientation) && TranslationTabsOrientation::tryFrom($orientation) instanceof TranslationTabsOrientation) {
            config()->set('capell-admin.translation_tabs_orientation', $orientation);
        }
    }

    /** Opens a dedicated multi-language page, so other captures keep their single-language record. */
    public static function pageEditUrl(): string
    {
        return PageResource::getUrl('edit', ['record' => self::translatedPage()]);
    }

    public static function translatedPage(): Page
    {
        return DB::transaction(static function (): Page {
            $template = RecordStateScreenshotFixture::page();
            $site = $template->site;

            $page = Page::query()->firstOrNew(['name' => 'Screenshot translated page']);
            if (! $page->exists) {
                $page = $template->replicate(['uuid']);
                $page->uuid = Str::uuid()->toString();
                $page->name = 'Screenshot translated page';
                $page->save();
            }

            $page->translations()->updateOrCreate(['language_id' => $site->language_id], ['title' => 'Summer collection', 'content' => '<p>Light layers for warm days.</p>']);

            foreach (self::LANGUAGES as $code => [$locale, $name, $title, $content]) {
                $language = Language::query()->firstOrNew(['code' => $code]);
                if (! $language->exists) {
                    $language = $site->language->replicate();
                    $language->fill(['code' => $code, 'locale' => $locale, 'name' => $name, 'flag' => $locale, 'default' => false]);
                    $language->save();
                }

                // A site's translations decide its languages, which the repeater names.
                $site->translations()->firstOrCreate(['language_id' => $language->getKey()], ['title' => $site->name]);
                $page->translations()->updateOrCreate(['language_id' => $language->getKey()], ['title' => $title, 'content' => $content]);
            }

            return $page;
        });
    }
}
