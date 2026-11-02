<?php

declare(strict_types=1);

namespace Workbench\App\Support;

use Capell\Admin\Filament\Resources\Pages\PageResource;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class LanguageActionScreenshotFixture
{
    private const string ParentName = 'Language coverage parent';

    private const string PageName = 'Language coverage page';

    public static function initialize(): Page
    {
        return DB::transaction(static function (): Page {
            $basePage = RecordStateScreenshotFixture::page();
            $site = $basePage->site;
            $primaryLanguage = $site->language;
            $otherLanguage = Language::query()->firstOrCreate(
                ['code' => 'fr'],
                ['name' => 'French', 'locale' => 'fr', 'default' => false, 'status' => true],
            );

            $site->siteDomains()->updateOrCreate(
                ['language_id' => $otherLanguage->getKey(), 'domain' => 'french.documentation.example'],
                ['scheme' => 'https', 'default' => false, 'status' => true],
            );

            $parent = Page::query()->firstOrNew(['name' => self::ParentName]);
            if (! $parent->exists) {
                $parent->uuid = Str::uuid()->toString();
            }
            $parent->fill([
                'site_id' => $site->getKey(),
                'layout_id' => $basePage->layout_id,
                'blueprint_id' => $basePage->blueprint_id,
            ])->save();
            $parent->translations()->updateOrCreate(
                ['language_id' => $primaryLanguage->getKey()],
                ['title' => self::ParentName, 'content' => '<p>Parent page.</p>', 'meta' => ['slug' => 'language-coverage-parent']],
            );

            $page = Page::query()->firstOrNew(['name' => self::PageName]);
            if (! $page->exists) {
                $page->uuid = Str::uuid()->toString();
            }
            $page->fill([
                'site_id' => $site->getKey(),
                'layout_id' => $basePage->layout_id,
                'blueprint_id' => $basePage->blueprint_id,
                'parent_id' => $parent->getKey(),
            ])->save();
            foreach ([$primaryLanguage, $otherLanguage] as $language) {
                $page->translations()->updateOrCreate(
                    ['language_id' => $language->getKey()],
                    ['title' => self::PageName, 'content' => '<p>Page translation.</p>', 'meta' => ['slug' => 'language-coverage-page']],
                );
            }

            return $page->refresh();
        });
    }

    public static function editUrl(): string
    {
        return PageResource::getUrl('edit', ['record' => self::initialize()]);
    }

    public static function destinationSite(): Site
    {
        return RecordStateScreenshotFixture::site();
    }
}
