<?php

declare(strict_types=1);

use Capell\Admin\Actions\Pages\IssuePagePreviewTokenAction;
use Capell\Core\Contracts\Themes\ThemePreviewRendererInterface;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Theme;
use Capell\Tests\Fixtures\Models\User;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Panel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

uses(CreatesAdminUser::class)
    ->group('preview');

it('rejects unsigned page preview requests', function (): void {
    $page = Page::factory()->createOne();

    test()->actingAsAdmin()
        ->get(route('capell.admin.preview-page', ['page' => $page]))
        ->assertForbidden();
});

it('renders signed page previews through the frontend preview renderer', function (): void {
    $theme = Theme::factory()->defaultMeta()->createOne(['key' => 'preview-route-theme']);
    $site = Site::factory()
        ->theme($theme)
        ->withTranslations()
        ->createOne(['name' => 'Preview Site']);
    $layout = Layout::factory()->site($site)->createOne();
    $page = Page::factory()
        ->site($site)
        ->layout($layout)
        ->withTranslations(languages: $site->language, data: ['content' => '<p>Draft preview body</p>'])
        ->createOne(['name' => 'Preview Draft']);

    $renderer = new class implements ThemePreviewRendererInterface
    {
        public ?Theme $theme = null;

        public ?Site $site = null;

        public ?Page $page = null;

        #[Override]
        public function render(
            Theme $theme,
            Site $site,
            Page $page,
            ?Language $language = null,
            ?SiteDomain $siteDomain = null,
        ): Response {
            $this->theme = $theme;
            $this->site = $site;
            $this->page = $page;

            return new Response('frontend preview response');
        }
    };

    app()->instance(ThemePreviewRendererInterface::class, $renderer);

    $response = test()->actingAsAdmin()
        ->get(IssuePagePreviewTokenAction::run($page));

    $response
        ->assertOk()
        ->assertSee('frontend preview response');

    expect((string) $response->headers->get('Cache-Control'))
        ->toContain('no-store')
        ->toContain('private')
        ->and($renderer->theme?->is($theme))->toBeTrue()
        ->and($renderer->site?->is($site))->toBeTrue()
        ->and($renderer->page?->is($page))->toBeTrue();
});

it('binds the chosen language into the signed draft preview and renderer', function (): void {
    test()->actingAsAdmin();
    $english = Language::factory()->english()->createOne();
    $french = Language::factory()->french()->createOne();
    $theme = Theme::factory()->defaultMeta()->createOne();
    $site = Site::factory()->language($english)->theme($theme)->withTranslations([$english, $french])->createOne();
    $page = Page::factory()->site($site)->withTranslations([$english, $french])->createOne();
    $renderer = Mockery::mock(ThemePreviewRendererInterface::class);
    $renderer->shouldReceive('render')->once()->withArgs(fn (Theme $theme, Site $site, Page $record, ?Language $language = null, ?SiteDomain $siteDomain = null): bool => $record->is($page) && $language?->is($french) === true && $siteDomain?->language_id === $french->id)
        ->andReturn(new Response('French preview'));
    app()->instance(ThemePreviewRendererInterface::class, $renderer);

    $url = IssuePagePreviewTokenAction::run($page, languageId: $french->id);
    expect($url)->toContain('language_id=' . $french->id);
    test()->get($url)->assertOk()->assertSee('French preview');
    test()->get(str_replace('language_id=' . $french->id, 'language_id=' . $english->id, $url))->assertForbidden();
});

it('explains an unavailable signed preview language without rendering another translation', function (): void {
    test()->actingAsAdmin();
    $english = Language::factory()->english()->createOne();
    $foreign = Language::factory()->french()->createOne();
    $theme = Theme::factory()->defaultMeta()->createOne();
    $site = Site::factory()->language($english)->theme($theme)->withTranslations($english)->createOne();
    $page = Page::factory()->site($site)->withTranslations($english)->createOne();
    $renderer = Mockery::mock(ThemePreviewRendererInterface::class);
    $renderer->shouldNotReceive('render');

    app()->instance(ThemePreviewRendererInterface::class, $renderer);
    $url = URL::temporarySignedRoute('capell.admin.preview-page', now()->addMinutes(10), [
        'page' => $page->id,
        'language_id' => $foreign->id,
    ]);

    test()->getJson($url)->assertUnprocessable()
        ->assertJsonPath('message', __('capell-admin::page_journey.missing_translation'));
});

it('retains preview access to assigned sites outside the current authoring scope', function (): void {
    $english = Language::factory()->english()->createOne();
    $french = Language::factory()->french()->createOne();
    $theme = Theme::factory()->defaultMeta()->createOne();
    $site = Site::factory()->language($english)->theme($theme)->withTranslations([$english, $french])->createOne();
    $page = Page::factory()->site($site)->withTranslations([$english, $french])->createOne();
    $actor = new class extends User
    {
        #[Override]
        public function isGlobalAdmin(): bool
        {
            return false;
        }

        /** @return Collection<int, int> */
        #[Override]
        public function getAssignedSiteIds(): Collection
        {
            return collect();
        }

        /** @return Collection<int, int> */
        public function getAllAssignedSiteIds(): Collection
        {
            return $this->assignedSiteIds;
        }

        #[Override]
        public function canAccessPanel(Panel $panel): bool
        {
            return true;
        }
    };
    $actor->forceFill(['id' => 99999]);
    $actor->assignedSiteIds = collect([$site->id]);

    test()->actingAs($actor);
    $renderer = Mockery::mock(ThemePreviewRendererInterface::class);
    $renderer->shouldReceive('render')->once()->withArgs(fn (Theme $theme, Site $site, Page $page, ?Language $language = null): bool => $language?->is($french) === true)
        ->andReturn(new Response('Assigned site French preview'));
    app()->instance(ThemePreviewRendererInterface::class, $renderer);
    $url = URL::temporarySignedRoute('capell.admin.preview-page', now()->addMinutes(10), [
        'page' => $page->id,
        'language_id' => $french->id,
    ]);

    test()->get($url)->assertOk()->assertSee('Assigned site French preview');
});
