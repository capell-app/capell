<?php

declare(strict_types=1);

use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Frontend\Livewire\Page\AbstractPage;
use Capell\Frontend\Support\State\FrontendState;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Livewire\Livewire;

it('rehydrates frontend context for livewire requests from an encrypted locked context token', function (): void {
    $language = Language::factory()->english()->create();
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

    $component = Livewire::test(FrontendContextTestPage::class)
        ->assertSet('stateToken', fn (?string $token): bool => is_string($token) && $token !== '');

    $token = $component->get('stateToken');

    expect($token)
        ->toBeString()
        ->not->toContain($page->getMorphClass());

    expect(json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR))->toMatchArray([
        'page_model' => $page->getMorphClass(),
        'page_id' => $page->getKey(),
        'url' => $page->pageUrl->full_url,
    ]);

    app()->forgetScopedInstances();

    $component
        ->call('captureContextProbe')
        ->assertSet('contextProbe', [
            'page_id' => $page->getKey(),
            'site_id' => $site->getKey(),
            'language_id' => $language->getKey(),
            'layout_id' => $layout->getKey(),
            'theme_id' => $theme->getKey(),
        ]);
});

it('does not restore frontend context from a stale disabled public url', function (): void {
    $language = Language::factory()->english()->create();
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

    $component = Livewire::test(FrontendContextTestPage::class);

    $page->pageUrl->update(['status' => false]);
    app()->forgetScopedInstances();

    $component
        ->call('tryCaptureContextProbe')
        ->assertSet('contextProbe', ['restored' => 0]);
});

final class FrontendContextTestPage extends AbstractPage
{
    /** @var array<string, int|null> */
    public array $contextProbe = [];

    /** @var array<string, int|null> */
    public array $setupProbe = [];

    protected function setup(): void
    {
        $this->setupProbe = $this->readContext();
    }

    public function captureContextProbe(): void
    {
        $this->contextProbe = $this->readContext();
    }

    public function tryCaptureContextProbe(): void
    {
        $this->contextProbe = ['restored' => resolve(FrontendState::class)->page() instanceof Pageable ? 1 : 0];
    }

    #[Override]
    public function render(): View
    {
        return view()->file(__DIR__ . '/../Fixtures/livewire-context-test.blade.php');
    }

    /** @return array<string, int|null> */
    private function readContext(): array
    {
        $state = resolve(FrontendState::class);
        $page = $state->page();

        return [
            'page_id' => $page instanceof Pageable ? (int) $page->getKey() : null,
            'site_id' => $state->site()?->getKey(),
            'language_id' => $state->language()?->getKey(),
            'layout_id' => $state->layout()?->getKey(),
            'theme_id' => $state->theme()?->getKey(),
        ];
    }
}
