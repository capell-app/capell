<?php

declare(strict_types=1);

use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Frontend\Livewire\Page\AbstractPage;
use Capell\Frontend\Support\Locale\FrontendLocaleScope;
use Capell\Frontend\Support\State\FrontendState;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Livewire\Livewire;

afterEach(function (): void {
    resolve(FrontendLocaleScope::class)->restore();
    Date::setLocale('en');
    CarbonImmutable::setLocale('en');
});

/**
 * Livewire updates POST to /livewire and never re-run the frontend.resolve
 * middleware, so the locale must be applied by the shared kernel step list that
 * the Livewire context restoration path also consumes.
 */
it('renders a livewire update in the language of the resolved page', function (): void {
    config()->set('app.locale', 'en');
    app()->setLocale('en');

    $language = Language::factory()->french(isDefault: true)->create();
    $theme = Theme::factory()->defaultMeta()->create();
    $site = Site::factory()
        ->theme($theme)
        ->withTranslations($language, siteDomainData: [
            'default' => true,
            'domain' => 'localhost',
            'scheme' => 'http',
            'path' => null,
        ])
        ->create(['language_id' => $language->id]);
    $layout = Layout::factory()->site($site)->create();
    $page = Page::factory()
        ->site($site)
        ->layout($layout)
        ->withTranslations($language)
        ->create();
    $page->load('pageUrl.siteDomain');

    app()->instance('request', Request::create($page->pageUrl->full_url, Symfony\Component\HttpFoundation\Request::METHOD_GET));

    resolve(FrontendState::class)
        ->withSite($site)
        ->withLanguage($language)
        ->withPage($page)
        ->withLayout($layout)
        ->withTheme($theme)
        ->withDomain($site->siteDomains->first())
        ->withRelativePath($page->pageUrl->url)
        ->setEffectiveUrl($page->pageUrl->url);

    $component = Livewire::test(FrontendLocaleTestPage::class);

    app()->forgetScopedInstances();
    app()->setLocale('en');

    $component
        ->call('captureLocaleProbe')
        ->assertSet('localeProbe', 'fr');
});

final class FrontendLocaleTestPage extends AbstractPage
{
    public ?string $localeProbe = null;

    public function captureLocaleProbe(): void
    {
        $this->localeProbe = app()->getLocale();
    }

    #[Override]
    public function render(): View
    {
        return view()->file(__DIR__ . '/../Fixtures/livewire-context-test.blade.php');
    }
}
