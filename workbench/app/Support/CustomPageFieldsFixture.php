<?php

declare(strict_types=1);

namespace Workbench\App\Support;

use Capell\Admin\Contracts\Extenders\PageSchemaExtender;
use Capell\Admin\Filament\Resources\Pages\PageResource;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Support\Cache\CapellCacheManager;
use Capell\Core\Support\Themes\LayoutViewRegistry;
use Capell\ExtensionCookbook\Filament\Extenders\ExtensionCookbookPageSchemaExtender;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Workbench\App\Support\Schemas\CaseStudyPageSchemaExtender;
use Workbench\App\Support\Schemas\FocusCustomPageFieldsScreenshot;

final class CustomPageFieldsFixture
{
    public static function registerSchemaExtenders(): void
    {
        require_once __DIR__ . '/../../fixtures/extension-cookbook/ExtensionCookbookPageSchemaExtender.php';
        app()->tag([CaseStudyPageSchemaExtender::class, ExtensionCookbookPageSchemaExtender::class, FocusCustomPageFieldsScreenshot::class], PageSchemaExtender::TAG);
    }

    public static function initialize(string $frontendOrigin): void
    {
        $origin = parse_url($frontendOrigin);
        throw_unless(is_array($origin)
            && in_array($origin['host'] ?? null, ['127.0.0.1', 'localhost', '::1'], true)
            && in_array($origin['scheme'] ?? null, ['http', 'https'], true)
            && in_array($origin['path'] ?? '', ['', '/'], true)
            && ! isset($origin['query']) && ! isset($origin['fragment']), RuntimeException::class, 'Custom-field screenshots require a local HTTP origin.');

        resolve(LayoutViewRegistry::class)->registerLayout('custom-page-fields::case-study', 'Case study');
        resolve(LayoutViewRegistry::class)->registerMaster('custom-page-fields::empty', 'Case study content');

        DB::transaction(static function () use ($origin): void {
            $site = Site::query()->first();
            $template = Blueprint::query()->pageType()->where('default', true)->first()
                ?? Blueprint::query()->pageType()->first();
            $sourceLayout = $site instanceof Site ? $site->layouts()->first() ?? Layout::query()->first() : null;
            throw_unless($site instanceof Site && $template instanceof Blueprint && $sourceLayout instanceof Layout, ModelNotFoundException::class, 'Prepare the owned screenshot workbench before creating the custom-field fixture.');

            $blueprint = Blueprint::query()->pageType()->where('key', 'case-study')->first();
            if (! $blueprint instanceof Blueprint) {
                $blueprint = $template->replicate();
                $blueprint->fill(['name' => 'Case study', 'key' => 'case-study', 'default' => false, 'admin' => ['configurator' => 'Default']])->save();
            }

            $layout = Layout::query()->firstOrNew(['site_id' => $site->getKey(), 'key' => 'custom-page-fields']);
            $layout->fill([
                'name' => 'Case study',
                'theme_id' => $sourceLayout->theme_id,
                'containers' => [],
                'meta' => ['layout_file' => 'custom-page-fields::case-study', 'master_file' => 'custom-page-fields::empty'],
            ])->save();

            foreach (['editor', 'validation', 'reopened', 'public'] as $scenario) {
                $page = Page::query()->firstOrNew(['name' => self::pageName($scenario)]);
                $page->fill([
                    'site_id' => $site->getKey(),
                    'layout_id' => $layout->getKey(),
                    'blueprint_id' => $blueprint->getKey(),
                    'visible_from' => now()->subDay(),
                    'visible_until' => null,
                    'meta' => ['case_study' => ['client_name' => $scenario === 'public' ? 'Field Notes Studio' : 'Field Notes']],
                ])->save();
                $page->translations()->updateOrCreate(['language_id' => $site->language_id], [
                    'title' => 'A clearer website for a growing studio',
                    'content' => '<p>A focused identity and website for the next chapter.</p>',
                    'meta' => ['slug' => 'custom-page-fields-' . $scenario],
                ]);
                $page->load(['translations', 'pageUrls']);
                $page->save();
            }

            $site->siteDomains()->updateOrCreate([
                'language_id' => $site->language_id,
                'domain' => $origin['host'],
                'scheme' => $origin['scheme'],
                'port' => $origin['port'] ?? null,
                'path' => null,
            ], ['status' => true]);

            resolve(CapellCacheManager::class)->flushCache();
        });
    }

    public static function editUrl(string $scenario): string
    {
        return PageResource::getUrl('edit', ['record' => self::page($scenario)]);
    }

    public static function page(string $scenario): Page
    {
        throw_unless(in_array($scenario, ['editor', 'validation', 'reopened', 'public'], true), RuntimeException::class, 'Unknown custom-field screenshot scenario.');

        return Page::query()->where('name', self::pageName($scenario))->firstOrFail();
    }

    private static function pageName(string $scenario): string
    {
        return 'Case study custom fields - ' . $scenario;
    }
}
