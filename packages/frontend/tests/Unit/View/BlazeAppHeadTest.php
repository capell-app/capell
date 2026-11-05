<?php

declare(strict_types=1);

use Capell\Core\Actions\RegisterBlazeOptimizedViewsAction;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Frontend\Data\Assets\FrontendResourcePlanData;
use Capell\Frontend\Data\FrontendContext;
use Capell\Frontend\Data\FrontendRuntimeManifestData;
use Capell\Frontend\Enums\RenderingStrategyEnum;
use Capell\Frontend\Facades\Frontend;
use Capell\Frontend\Tests\FrontendTestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Engines\CompilerEngine;
use Livewire\Blaze\BladeService;
use Livewire\Blaze\BlazeManager;
use Livewire\Blaze\BlazeServiceProvider;
use Livewire\Blaze\Runtime\BlazeRuntime;
use Livewire\Blaze\Support\Utils as BlazeUtils;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;

// Pest does not initialise PHPUnit’s Composer path for isolated processes.
if (! defined('PHPUNIT_COMPOSER_INSTALL')) {
    define('PHPUNIT_COMPOSER_INSTALL', dirname(__DIR__, 5) . '/vendor/autoload.php');
}

final class BlazeAppHeadTest extends FrontendTestCase
{
    // Blaze defines compiled component functions for the lifetime of the PHP process.
    #[Test]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function renders_the_public_head_when_blaze_compiles_the_anonymous_component(): void
    {
        app()->register(BlazeServiceProvider::class);

        $componentFixturePath = __DIR__ . '/../../Fixtures/views/components';
        $customHeadComponentPath = $componentFixturePath . '/app/head/custom.blade.php';
        $tokenComponentPath = $componentFixturePath . '/app/head/tokens.blade.php';
        $headComponentPath = realpath(__DIR__ . '/../../../resources/views/components/app/head/index.blade.php');

        throw_unless(is_string($headComponentPath), RuntimeException::class, 'Expected the public head component path.');

        $blazeBladeService = resolve(BladeService::class);
        $blazeViewFactory = new ReflectionProperty(BladeService::class, 'view')->getValue($blazeBladeService);
        $bladeEngine = View::getEngineResolver()->resolve('blade');

        throw_unless($bladeEngine instanceof CompilerEngine, RuntimeException::class, 'Expected the Blade compiler engine.');

        $bladeCompiler = $bladeEngine->getCompiler();

        throw_unless($bladeCompiler instanceof BladeCompiler, RuntimeException::class, 'Expected the Blade compiler.');

        View::addNamespace('capell-frontend-test', $componentFixturePath);
        $blazeViewFactory->addNamespace('capell-frontend-test', $componentFixturePath);
        View::getFinder()->prependNamespace('capell', dirname($componentFixturePath));
        $blazeViewFactory->getFinder()->prependNamespace('capell', dirname($componentFixturePath));

        foreach ([$bladeCompiler, $blazeBladeService->compiler] as $componentCompiler) {
            $componentCompiler->component('capell-frontend-test::app.head.custom', 'capell::app.head.custom');
            $componentCompiler->component('capell-frontend-test::app.head.tokens', 'capell::app.head.tokens');
        }

        expect(RegisterBlazeOptimizedViewsAction::run($componentFixturePath))->toBeTrue()
            ->and(RegisterBlazeOptimizedViewsAction::run($headComponentPath))->toBeTrue();
        expect(realpath($blazeBladeService->componentNameToPath('capell::app.head.custom')))
            ->toBe(realpath($customHeadComponentPath));

        File::delete($bladeCompiler->getCompiledPath($customHeadComponentPath));
        File::delete($bladeCompiler->getCompiledPath($headComponentPath));
        File::delete($bladeCompiler->getCompiledPath($tokenComponentPath));

        bindBlazeHeadTestContext();

        $blazeRuntime = resolve(BlazeRuntime::class);
        $resolvedHeadComponentPath = $blazeBladeService->componentNameToPath('capell::app.head');
        expect(realpath($resolvedHeadComponentPath))->toBe(realpath($headComponentPath));
        $headComponentHash = BlazeUtils::hash($resolvedHeadComponentPath);
        $headFunction = '_' . $headComponentHash;
        // resolve() compiles and loads the component in both supported Blaze APIs.
        expect($blazeRuntime->resolve('capell::app.head'))->toBe($headComponentHash);

        throw_unless(function_exists($headFunction), RuntimeException::class, 'Expected the Blaze head function to be compiled.');

        $renderHead = Closure::fromCallable($headFunction);

        ob_start();
        $renderHead($blazeRuntime, ['livewireEnabled' => false]);
        $html = ob_get_clean();

        expect($html)
            ->toBeString()
            ->toContain('<head>')
            ->toContain('<title>')
            ->toContain('name="blaze-head-token"')
            ->toContain('content="BLAZE-HEAD"')
            ->and(resolve(BlazeManager::class)->isEnabled())->toBeTrue();
    }
}

/** @param array<string, mixed> $params */
function bindBlazeHeadTestContext(array $params = [], ?Theme $theme = null): void
{
    $language = Language::factory()->createOne();
    $theme ??= Theme::factory()->defaultMeta()->create();
    $site = Site::factory()
        ->language($language)
        ->theme($theme)
        ->withTranslations($language, ['title' => 'Example Site'])
        ->create();
    $layout = Layout::factory()->site($site)->create();
    $page = Page::factory()
        ->site($site)
        ->layout($layout)
        ->withTranslations($language, ['title' => 'Example Page'])
        ->create();

    $site->load(['language', 'siteDomain', 'siteDomains', 'translation']);
    $page->load(['pageUrl', 'pageUrls.language', 'pageUrls.siteDomain', 'translation']);

    $runtimeManifest = FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::BladeOnly);
    $resourcePlan = new FrontendResourcePlanData([], [], [], [], [], [], [], hash('sha256', 'empty'));

    Frontend::swap(new FrontendContext(
        site: $site,
        language: $language,
        page: $page,
        layout: $layout,
        theme: $theme,
        params: [
            'resourcePlan' => $resourcePlan,
            'runtimeManifest' => $runtimeManifest,
            ...$params,
        ],
        slug: null,
    ));
}
