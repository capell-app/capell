<?php

declare(strict_types=1);

namespace Capell\Tests\Integration\Feature;

use Capell\Admin\Actions\CreateDefaultPagesAction;
use Capell\Admin\Filament\Pages\GuidedSetup;
use Capell\Admin\Tests\AdminTestCase;
use Capell\Core\Actions\CreateDefaultLanguagesAction;
use Capell\Core\Actions\FirstRun\MarkFreshInstallSitesAction;
use Capell\Core\Actions\FirstRun\ResolveGuidedSetupStateAction;
use Capell\Core\Actions\PlanSiteSpecAction;
use Capell\Core\Data\SiteSpec\CapellSiteSpecData;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Core\Support\SiteSpec\SiteSpecApplierRegistry;
use Capell\Frontend\Contracts\SettingsMigrationProviderInterface;
use Capell\Frontend\Providers\FrontendServiceProvider;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use MichalOravec\PaginateRoute\PaginateRouteServiceProvider;
use Override;
use ReflectionClass;
use RuntimeException;

/** Exercises real optional packages when installed, without making them Core dependencies. */
final class GuidedSetupPackagesTest extends AdminTestCase
{
    /** @var array<string, string> */
    private const array PACKAGES = [
        'capell-app/block-library' => 'Capell\\BlockLibrary\\Providers\\BlockLibraryServiceProvider',
        'capell-app/layout-builder' => 'Capell\\LayoutBuilder\\LayoutBuilderServiceProvider',
        'capell-app/navigation' => 'Capell\\Navigation\\Providers\\NavigationServiceProvider',
    ];

    private const string FORM_PROVIDER = 'Capell\\FormBuilder\\Providers\\FormBuilderServiceProvider';

    protected function setUp(): void
    {
        parent::setUp();
        if (! $this->packagesPresent()) {
            self::markTestSkipped('Requires installed Navigation and Layout Builder; the contact form case also requires Form Builder.');
        }

        $this->registerAndMigrateSettings(resolve(SettingsMigrationProviderInterface::class)->getSettingMigrations(), dirname(__DIR__, 3) . '/packages/frontend/database/settings');
        CreateDefaultLanguagesAction::run(['en']);
        Queue::fake();
    }

    public function test_it_completes_setup_with_a_menu_and_contact_form(): void
    {
        $this->completeSetup(withForm: true);
    }

    public function test_it_completes_setup_without_form_builder(): void
    {
        $this->completeSetup(withForm: false);
    }

    /** @return list<class-string> */
    #[Override]
    protected function getPackageProviders(mixed $app): array
    {
        return [
            ...parent::getPackageProviders($app),
            ...($this->packagesPresent() ? [FrontendServiceProvider::class, PaginateRouteServiceProvider::class, ...array_values($this->providers())] : []),
        ];
    }

    #[Override]
    protected function getEnvironmentSetUp(mixed $app): void
    {
        parent::getEnvironmentSetUp($app);
        if (! $this->packagesPresent()) {
            return;
        }

        CapellCore::forcePackageInstalled(FrontendServiceProvider::$packageName);
        foreach ($this->providers() as $name => $provider) {
            CapellCore::registerPackage($name, serviceProviderClass: $provider, path: $this->packageRoot($provider, $name));
            CapellCore::forcePackageInstalled($name);
        }
    }

    /** @param array<int, string>|null $packages */
    #[Override]
    protected function registerPackageConfigs(Application $app, ?array $packages = null): void
    {
        parent::registerPackageConfigs($app, $packages);
        $this->registerPublishConfig('frontend');
    }

    #[Override]
    protected function shouldResetTestbenchMigrationState(): bool
    {
        return true;
    }

    private function completeSetup(bool $withForm): void
    {
        $this->actingAsAdmin();
        $english = Language::query()->where('code', 'en')->firstOrFail();
        $theme = Theme::factory()->createOne(['key' => 'default', 'default' => true]);
        $site = Site::factory()->language($english)->withTranslations($english)->createOne(['theme_id' => $theme->getKey(), 'name' => 'My Laravel App']);
        Layout::factory()->site($site)->createOne([
            'theme_id' => $theme->getKey(), 'key' => 'default', 'default' => true,
            'containers' => ['main' => ['widgets' => [['widget_key' => 'page-content', 'occurrence' => 1]]]],
        ]);
        CreateDefaultPagesAction::run($site->refresh(), $site->languages);
        MarkFreshInstallSitesAction::run();
        $registry = resolve(SiteSpecApplierRegistry::class);
        $this->assertTrue($registry->has('navigation'));
        $this->assertSame($withForm, $registry->has('forms'));

        $wizard = Livewire::test(GuidedSetup::class);
        $preview = new DOMDocument;
        @$preview->loadHTML($wizard->instance()->previewHtml());
        $links = new DOMXPath($preview)->query('//nav//a');
        $this->assertNotFalse($links);
        $this->assertSame(5, $links->length);
        $wizard->fillForm([
            'presetKey' => 'service-business', 'siteName' => 'Hartley Dental',
            'tagline' => 'Family dentist in Leeds', 'primaryColour' => '#0f766e',
            'pageSlugs' => ['about', 'services', 'reviews', 'contact'], 'contactEmail' => 'reception@hartley.test',
        ])->call('createPages')->assertHasNoFormErrors()->assertSet('created', true)->assertSet('createdPageCount', 5);

        $site->refresh();
        $this->assertSame('Hartley Dental', $site->name);
        $this->assertNotNull(data_get($site->meta, 'guided_setup.completed_at'));
        $spec = CapellSiteSpecData::from(data_get($site->meta, 'guided_setup.spec'));
        $all = PlanSiteSpecAction::make()->existingPagesBySlug($spec, $site);
        $found = [];
        foreach ($spec->pages as $page) {
            $found[$page->slug] = $all[$page->meta['slug'] ?? $page->slug];
        }

        $this->assertSame(['home', 'about', 'services', 'reviews', 'contact'], array_keys($found));
        foreach (array_diff(array_keys($found), ['home']) as $slug) {
            $this->assertTrue(ResolveGuidedSetupStateAction::isPrivateDraft($found[$slug]['page']));
        }

        // The install's Coming soon home is retained; the completed Home draft is kept in the stored private spec.
        $this->assertSame('private', $spec->initialVisibility);
        $this->assertSame('Welcome', $spec->pages[0]->title);
        $this->assertSame(8, $site->pages()->count());

        $navigation = $this->modelClass('Capell\\Navigation\\Models\\Navigation')::query()->where('site_id', $site->getKey())->where('key', 'main')->firstOrFail();
        $this->assertSame(array_map(static fn (array $entry): int => (int) $entry['page']->getKey(), array_values($found)), array_column(array_column(json_decode((string) $navigation->getRawOriginal('items'), true, flags: JSON_THROW_ON_ERROR), 'data'), 'pageable_id'));

        $contact = $found['contact']['page']->refresh()->load('layout');
        $containers = $contact->layout->containers ?? [];
        $widgets = array_merge(...array_map(static fn (array $container): array => $container['widgets'] ?? [], array_values($containers)));
        $formWidgetKeys = array_values(array_filter(array_column($widgets, 'widget_key'), static fn (string $key): bool => str_starts_with($key, 'form-builder-form-')));
        if ($withForm) {
            $form = $this->modelClass('Capell\\FormBuilder\\Models\\Form')::query()->where('site_id', $site->getKey())->where('handle', 'contact')->sole();
            $this->assertTrue((bool) $form->getAttribute('is_active'));
            $this->assertSame('reception@hartley.test', data_get($form->getAttribute('settings'), 'notificationEmail'));
            $this->assertSame(['form-builder-form-contact'], $formWidgetKeys);
            $widget = $this->modelClass('Capell\\LayoutBuilder\\Models\\Widget')::query()->where('key', $formWidgetKeys[0])->sole();
            $this->assertSame('contact', data_get($widget->getAttribute('meta'), 'form_handle'));
            $this->assertNotSame($contact->layout_id, $found['about']['page']->layout_id);
        } else {
            $this->assertSame([], $formWidgetKeys);
            $this->assertArrayNotHasKey('forms', $spec->packageData);
        }
    }

    /** @return array<string, class-string<ServiceProvider>> */
    private function providers(): array
    {
        $providers = self::PACKAGES;
        if ($this->name() !== 'test_it_completes_setup_without_form_builder') {
            $providers['capell-app/form-builder'] = self::FORM_PROVIDER;
        }

        $present = [];
        foreach ($providers as $name => $provider) {
            if (is_subclass_of($provider, ServiceProvider::class)) {
                $present[$name] = $provider;
            }
        }

        return $present;
    }

    private function packagesPresent(): bool
    {
        return count($this->providers()) === count(self::PACKAGES) + ($this->name() === 'test_it_completes_setup_without_form_builder' ? 0 : 1);
    }

    /** @return class-string<Model> */
    private function modelClass(string $class): string
    {
        throw_unless(is_subclass_of($class, Model::class), RuntimeException::class, 'Missing installed model: ' . $class);

        return $class;
    }

    /** @param class-string $provider */
    private function packageRoot(string $provider, string $name): string
    {
        $path = new ReflectionClass($provider)->getFileName();
        throw_unless(is_string($path), RuntimeException::class);
        $directory = dirname($path);
        while (true) {
            $manifest = $directory . '/composer.json';
            if (is_file($manifest) && data_get(json_decode((string) file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR), 'name') === $name) {
                return $directory;
            }

            $parent = dirname($directory);
            throw_if($parent === $directory, RuntimeException::class, 'Cannot locate installed package: ' . $name);
            $directory = $parent;
        }
    }
}
