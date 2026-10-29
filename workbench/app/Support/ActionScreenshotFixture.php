<?php

declare(strict_types=1);

namespace Workbench\App\Support;

use Capell\Admin\Filament\Resources\Pages\PageResource;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ActionScreenshotFixture
{
    public static function initialize(): void
    {
        RecordStateScreenshotFixture::initialize();

        DB::transaction(static function (): void {
            $template = RecordStateScreenshotFixture::page();
            $site = $template->site;
            $language = $site->language;
            $otherLanguage = Language::query()->firstOrNew(['code' => 'fr-shot']);
            if (! $otherLanguage->exists) {
                $otherLanguage = $language->replicate();
                $otherLanguage->fill(['code' => 'fr-shot', 'locale' => 'fr', 'name' => 'Screenshot French', 'default' => false]);
                $otherLanguage->save();
            }

            $otherSite = Site::query()->firstOrNew(['name' => 'Screenshot French site']);
            if (! $otherSite->exists) {
                $otherSite = $site->replicate(['uuid']);
                $otherSite->fill(['name' => 'Screenshot French site', 'language_id' => $otherLanguage->getKey(), 'default' => false]);
                $otherSite->save();
            }

            $parent = Page::query()->firstOrNew(['name' => 'Screenshot parent page']);
            if (! $parent->exists) {
                $parent = $template->replicate(['uuid']);
                $parent->uuid = Str::uuid()->toString();
                $parent->name = 'Screenshot parent page';
                $parent->parent_id = null;
                $parent->save();
            }

            $parent->translations()->updateOrCreate(['language_id' => $site->language_id], ['title' => 'Screenshot parent page', 'content' => '<p>Parent page.</p>', 'meta' => ['slug' => 'screenshot-parent']]);
            $child = Page::query()->firstOrNew(['name' => 'Screenshot child page']);
            if (! $child->exists) {
                $child = $template->replicate(['uuid']);
                $child->uuid = Str::uuid()->toString();
                $child->name = 'Screenshot child page';
            }

            $child->parent_id = $parent->getKey();
            $child->save();
            $child->translations()->updateOrCreate(['language_id' => $site->language_id], ['title' => 'Screenshot child page', 'content' => '<p>Child page.</p>', 'meta' => ['slug' => 'screenshot-child']]);
        });
    }

    public static function createUrl(): string
    {
        return PageResource::getUrl('create', ['site_id' => RecordStateScreenshotFixture::page()->site_id]);
    }

    public static function parentHintUrl(): string
    {
        return PageResource::getUrl('edit', ['record' => Page::query()->where('name', 'Screenshot child page')->firstOrFail()]);
    }
}
