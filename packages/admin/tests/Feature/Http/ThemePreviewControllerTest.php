<?php

declare(strict_types=1);

use Capell\Admin\Actions\Themes\CreateThemePreviewUrlAction;
use Capell\Core\Enums\ContentStructure;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Core\ThemeStudio\Preview\ThemePreviewSigner;
use Capell\Frontend\Contracts\SettingsMigrationProviderInterface;
use Capell\Frontend\Providers\FrontendServiceProvider;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Collection as SupportCollection;

/**
 * @return array{0: Theme, 1: Site, 2: Page}
 */
function createThemePreviewTestRecords(): array
{
    $activeTheme = Theme::withoutEvents(fn (): Theme => Theme::factory()->defaultMeta()->create([
        'key' => 'active-preview-test',
        'meta' => [
            'colors' => [
                'primary' => '1, 2, 3',
                'secondary' => '4, 5, 6',
            ],
        ],
    ]));
    $previewTheme = Theme::withoutEvents(fn (): Theme => Theme::factory()->defaultMeta()->create([
        'key' => 'preview-theme-test',
        'meta' => [
            'colors' => [
                'primary' => '12, 34, 56',
                'secondary' => '65, 43, 21',
            ],
        ],
    ]));
    $site = Site::factory()->theme($activeTheme)->withTranslations()->create();
    $layout = Layout::factory()->default()->create();
    $page = Page::factory()
        ->site($site)
        ->layout($layout)
        ->home()
        ->withTranslations(
            languages: $site->language,
            data: ['content' => '<p>Preview page content</p>'],
            slug: '/',
            contentStructure: ContentStructure::Html,
        )
        ->create();

    createPreviewThemeOverride($previewTheme);

    return [$previewTheme, $site, $page];
}

function createPreviewThemeOverride(Theme $theme): void
{
    $viewDirectory = resource_path('themes/' . $theme->key . '/livewire/page');

    if (! is_dir($viewDirectory)) {
        mkdir($viewDirectory, 0755, true);
    }

    file_put_contents($viewDirectory . '/page.blade.php', '<div>Preview theme override rendered</div>');
}

/** @param SupportCollection<int, int> $assignedSiteIds */
function createThemePreviewPanelUser(bool $canAccessPanel, SupportCollection $assignedSiteIds): Authenticatable
{
    $user = new class($canAccessPanel, $assignedSiteIds) extends Authenticatable implements FilamentUser
    {
        /** @use HasFactory<Factory<self>> */
        use HasFactory;

        /** @var SupportCollection<int, int> */
        public SupportCollection $assignedSiteIds;

        protected $table = 'users';

        /**
         * @param  SupportCollection<int, int>  $assignedSiteIds
         */
        public function __construct(
            public bool $panelAccess = false,
            ?SupportCollection $assignedSiteIds = null,
        ) {
            parent::__construct();
            $this->assignedSiteIds = $assignedSiteIds ?? collect();
        }

        public function canAccessPanel(Panel $panel): bool
        {
            return $this->panelAccess;
        }

        public function isGlobalAdmin(): bool
        {
            return false;
        }

        /**
         * @return SupportCollection<int, int>
         */
        public function getAssignedSiteIds(): SupportCollection
        {
            return $this->assignedSiteIds;
        }
    };

    $user->forceFill([
        'name' => 'Theme Preview User',
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
    ]);

    return $user;
}

beforeEach(function (): void {
    app()->register(FrontendServiceProvider::class);

    test()->registerAndMigrateSettings(
        resolve(SettingsMigrationProviderInterface::class)->getSettingMigrations(),
        dirname(__DIR__, 4) . '/frontend/database/settings',
    );

    Blueprint::factory()->theme()->default()->create();
});

it('rejects unsigned preview requests', function (): void {
    [$theme, $site, $page] = createThemePreviewTestRecords();

    test()->get(route('capell.admin.theme-preview', [
        'theme' => $theme,
        'site' => $site,
        'page' => $page,
    ]))->assertForbidden();
});

it('redirects signed unauthenticated preview requests to admin login', function (): void {
    [$theme, $site, $page] = createThemePreviewTestRecords();

    test()->get(CreateThemePreviewUrlAction::run($theme, $site, $page))
        ->assertRedirect('/admin/login');
});

it('adds a preview token for the selected preset', function (): void {
    [$theme, $site, $page] = createThemePreviewTestRecords();
    $signer = new ThemePreviewSigner('theme-preview-test-secret');

    app()->instance(ThemePreviewSigner::class, $signer);

    $url = CreateThemePreviewUrlAction::run($theme, $site, $page, 'editorial');
    $query = [];
    $queryString = parse_url($url, PHP_URL_QUERY);
    parse_str(is_string($queryString) ? $queryString : '', $query);

    expect($query)->toHaveKey($signer->tokenParam());

    $token = $query[$signer->tokenParam()] ?? '';
    $context = $signer->contextFromToken(is_string($token) ? $token : '');

    expect($context->previewing)->toBeTrue()
        ->and($context->themeKey)->toBe($theme->key)
        ->and($context->presetKey)->toBe('editorial');
});

it('renders signed theme previews for authenticated admins without authoring surface', function (): void {
    [$theme, $site, $page] = createThemePreviewTestRecords();

    test()->actingAsAdmin();

    $response = test()->get(CreateThemePreviewUrlAction::run($theme, $site, $page));

    $response
        ->assertOk()
        ->assertHeader('Cache-Control');

    expect((string) $response->headers->get('Cache-Control'))
        ->toContain('no-store')
        ->toContain('private');

    $response
        ->assertSee('--color-primary:rgb(12, 34, 56)', false)
        ->assertDontSee('--color-primary:rgb(1, 2, 3)', false)
        ->assertDontSee('data-capell-editor', false)
        ->assertDontSee('signedEditorUrl', false);
});

it('rejects signed preview requests from authenticated users without admin panel access', function (): void {
    [$theme, $site, $page] = createThemePreviewTestRecords();

    test()->actingAs(createThemePreviewPanelUser(
        canAccessPanel: false,
        assignedSiteIds: collect([$site->getKey()]),
    ));

    test()->get(CreateThemePreviewUrlAction::run($theme, $site, $page))
        ->assertForbidden();
});

it('rejects signed preview requests from site-scoped admins for unassigned sites', function (): void {
    [$theme, $site, $page] = createThemePreviewTestRecords();

    test()->actingAs(createThemePreviewPanelUser(
        canAccessPanel: true,
        assignedSiteIds: collect(),
    ));

    test()->get(CreateThemePreviewUrlAction::run($theme, $site, $page))
        ->assertForbidden();
});
