<?php

declare(strict_types=1);

namespace Capell\Tests\Integration\Feature;

use Capell\Admin\Actions\FirstRun\BuildGuidedSetupPreviewAction;
use Capell\Admin\Tests\AdminTestCase;
use Capell\Core\Actions\CreateDefaultLanguagesAction;
use Capell\Core\Contracts\SiteSpec\SiteSpecApplier;
use Capell\Core\Data\FirstRun\GuidedSetupInputData;
use Capell\Core\Data\SiteSpec\CapellSiteSpecData;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Core\Support\SiteSpec\SiteSpecApplierRegistry;
use Capell\Core\ThemeStudio\Data\ThemeDefinitionData;
use Capell\Core\ThemeStudio\Theme\ThemeRegistry;
use Capell\Tests\Support\FirstRun\GuidedSetupEventsNavigationRenderer;
use Composer\Autoload\ClassLoader;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Blade;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/** Uses the installed themes' actual templates; Core does not require optional themes. */
final class GuidedSetupThemePreviewTest extends AdminTestCase
{
    /** @return iterable<string, array{?ThemeDefinitionData, string}> */
    public static function registeredThemes(): iterable
    {
        $paths = [];
        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            foreach ($loader->getPrefixesPsr4() as $directories) {
                foreach ($directories as $directory) {
                    $path = dirname(rtrim($directory, '/'));
                    if (is_file($path . '/capell.json')) {
                        $paths[$path] = $path;
                    }
                }
            }
        }

        $themes = [];
        foreach ($paths as $path) {
            $manifest = json_decode((string) file_get_contents($path . '/capell.json'), true, flags: JSON_THROW_ON_ERROR);
            if (($manifest['kind'] ?? null) !== 'theme') {
                continue;
            }

            foreach ($manifest['providers']['metadata'] ?? [] as $provider) {
                if (is_string($provider) && is_callable([$provider, 'definition'])) {
                    $definition = call_user_func([$provider, 'definition']);
                    throw_unless($definition instanceof ThemeDefinitionData, RuntimeException::class, 'Theme provider must supply a theme definition: ' . $provider);

                    $themes[$definition->key] = [$definition, $path];
                }
            }
        }

        if ($themes === []) {
            yield 'no installed themes' => [null, ''];

            return;
        }

        ksort($themes);
        yield from $themes;
        if (isset($themes['events'])) {
            [$events, $path] = $themes['events'];
            $events = clone $events;
            $events->frontend['setupPreviewNavigationRenderer'] = GuidedSetupEventsNavigationRenderer::class;
            yield 'events with public navigation DTOs' => [$events, $path];
        }
    }

    #[DataProvider('registeredThemes')]
    public function test_every_registered_theme_keeps_unsaved_content_and_navigation(?ThemeDefinitionData $selected, string $selectedPath): void
    {
        if (! $selected instanceof ThemeDefinitionData) {
            self::markTestSkipped('Requires installed theme packages.');
        }

        resolve(SiteSpecApplierRegistry::class)->register(new class implements SiteSpecApplier
        {
            #[Override]
            public function key(): string
            {
                return 'navigation';
            }

            #[Override]
            public function apply(CapellSiteSpecData $spec, Site $site, array $pagesBySlug): void
            {
                throw new RuntimeException('A preview must not apply navigation.');
            }
        });
        $registry = resolve(ThemeRegistry::class);
        foreach (self::registeredThemes() as [$definition, $path]) {
            $this->assertInstanceOf(ThemeDefinitionData::class, $definition);
            $registry->register($definition);
            CapellCore::registerPackage($definition->package, path: $path);
            $namespace = str_replace('capell-app/', 'capell-', $definition->package);
            view()->addNamespace($namespace, $path . '/resources/views');
            Blade::anonymousComponentPath($path . '/resources/views/components', $namespace);
        }

        $registry->register($selected);
        $this->assertSame($selected, $registry->definition($selected->key));
        $this->assertSame($selectedPath, CapellCore::getPackage($selected->package)->path);
        CreateDefaultLanguagesAction::run(['en']);
        $english = Language::query()->where('code', 'en')->firstOrFail();
        $theme = Theme::factory()->createOne(['key' => $selected->key]);
        $site = Site::factory()->language($english)->withTranslations($english)->createOne(['theme_id' => $theme->getKey()]);

        $html = BuildGuidedSetupPreviewAction::run(new GuidedSetupInputData(
            presetKey: 'service-business',
            siteName: 'Hartley Dental',
            tagline: 'Family dentist in Leeds',
            themeKey: $selected->key,
            pageSlugs: ['about', 'services', 'reviews', 'contact'],
        ), $site);
        $document = new DOMDocument;
        @$document->loadHTML($html);
        $xpath = new DOMXPath($document);
        $body = $document->getElementsByTagName('body')->item(0);
        $aboutLinks = $xpath->query('//nav//a[@href="#page-about"]');
        $homeArticles = $xpath->query('//article[@id="page-home"]');
        $this->assertInstanceOf(DOMElement::class, $body);
        $this->assertNotFalse($aboutLinks);
        $this->assertNotFalse($homeArticles);
        $about = $aboutLinks->item(0);
        $this->assertInstanceOf(DOMElement::class, $about);
        $this->assertStringContainsString('Hartley Dental', $body->textContent);
        $this->assertStringContainsString('Family dentist in Leeds', $body->textContent);
        $this->assertSame('About', $about->textContent);
        $this->assertSame(1, $homeArticles->length);
        foreach (['wire:', 'data-capell-edit', 'signature=', '<script'] as $internal) {
            $this->assertStringNotContainsString($internal, (string) $html);
        }

        if (isset($selected->frontend['setupPreviewNavigationRenderer'])) {
            $this->assertStringContainsString('mst-navbar-wordmark', (string) $html);
        }

        if ($selected->key === 'paperdesk') {
            $this->assertStringContainsString('data-dsk-heading-font=', (string) $html);
            $this->assertStringContainsString('dsk-shell', (string) $html);
        }
    }
}
