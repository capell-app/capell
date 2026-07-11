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
use Capell\Tests\Support\Concerns\CreatesAdminUser;
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
