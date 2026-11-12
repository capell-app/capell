<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Pages;

use Capell\Admin\Filament\Resources\Sites\SiteResource;
use Capell\Core\Actions\FirstRun\BuildGuidedSetupSpecAction;
use Capell\Core\Actions\FirstRun\CompleteGuidedSetupAction;
use Capell\Core\Actions\FirstRun\ResolveGuidedSetupStateAction;
use Capell\Core\Actions\FirstRun\SkipGuidedSetupAction;
use Capell\Core\Actions\GetEditPageResourceUrlAction;
use Capell\Core\Actions\PublishSiteSpecSiteAction;
use Capell\Core\Data\FirstRun\GuidedSetupInputData;
use Capell\Core\Data\FirstRun\GuidedSetupStateData;
use Capell\Core\Data\SiteSpec\SiteSpecPresetData;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Theme;
use Capell\Core\Support\SiteSpec\SiteSpecApplierRegistry;
use Capell\Core\Support\SiteSpec\SiteSpecPresetRegistry;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page as FilamentPage;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Override;
use RuntimeException;
use Throwable;

/**
 * The first screen a new administrator sees: four short steps that turn an empty site into a
 * private set of draft pages. It only gathers answers; the pages are written by the SiteSpec
 * apply path in Core, and nothing is public until the person chooses to publish.
 */
class GuidedSetup extends FilamentPage
{
    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** Fixed when the page opens: finishing setup ends the state that found the site, so later requests keep this id. */
    public ?int $siteId = null;

    public bool $created = false;

    public int $createdPageCount = 0;

    public ?string $homeUrl = null;

    public bool $sitePublished = false;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'setup';

    protected string $view = 'capell-admin::filament.pages.guided-setup';

    private ?Site $resolvedSite = null;

    #[Override]
    public static function canAccess(): bool
    {
        $actor = auth()->user();

        if ($actor === null) {
            return false;
        }

        // Still reachable after the pages are created, until they are published, so the person can finish.
        $site = self::editableSite() ?? ResolveGuidedSetupStateAction::make()->awaitingPublication($actor);

        return $site instanceof Site && SiteResource::canEdit($site);
    }

    /** Whether the person should be taken to setup now: it is owed and they may do it. */
    public static function isOwed(): bool
    {
        $site = self::editableSite();

        return $site instanceof Site && SiteResource::canEdit($site);
    }

    #[Override]
    public static function getNavigationLabel(): string
    {
        return (string) __('capell-admin::setup.title');
    }

    /** The site guided setup is for: the first one the person can reach, so it matches the dashboard redirect. */
    public static function editableSite(): ?Site
    {
        $actor = auth()->user();

        if ($actor === null) {
            return null;
        }

        $state = ResolveGuidedSetupStateAction::run($actor);

        return $state->needsSetup && $state->siteId !== null ? Site::query()->whereKey($state->siteId)->first() : null;
    }

    #[Override]
    public function getTitle(): string|Htmlable
    {
        return __('capell-admin::setup.title');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $site = self::editableSite() ?? abort(403);
        $this->siteId = (int) $site->getKey();
        $state = GuidedSetupStateData::forSite($site);
        $presets = $this->presets();

        $this->formSchema()->fill([
            'presetKey' => $this->defaultPresetKey($presets, $state),
            'siteName' => $site->name,
            'tagline' => '',
            'themeKey' => null,
            'primaryColour' => null,
            'pageSlugs' => [],
        ]);
        $this->selectAllPages();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Wizard::make([
                    Step::make('business')
                        ->label(__('capell-admin::setup.step_business'))
                        ->schema([
                            Radio::make('presetKey')
                                ->label(__('capell-admin::setup.step_business'))
                                ->hint(__('capell-admin::setup.business_help'))
                                ->options(fn (): array => collect($this->presets())->mapWithKeys(fn (SiteSpecPresetData $preset): array => [$preset->key => $preset->label])->all())
                                ->descriptions(fn (): array => collect($this->presets())->mapWithKeys(fn (SiteSpecPresetData $preset): array => [$preset->key => $preset->description])->all())
                                ->live()
                                ->afterStateUpdated(fn () => $this->selectAllPages())
                                ->required(),
                            TextInput::make('siteName')
                                ->label(__('capell-admin::setup.site_name'))
                                ->required()
                                ->maxLength(255)
                                ->live(onBlur: true),
                            TextInput::make('tagline')
                                ->label(__('capell-admin::setup.one_liner'))
                                ->helperText(__('capell-admin::setup.one_liner_help'))
                                ->maxLength(500)
                                ->live(onBlur: true),
                        ]),
                    Step::make('look')
                        ->label(__('capell-admin::setup.step_look'))
                        ->schema([
                            Select::make('themeKey')
                                ->label(__('capell-admin::setup.theme'))
                                ->helperText(__('capell-admin::setup.theme_help'))
                                ->options(fn (): array => Theme::query()->whereNotNull('key')->orderBy('name')->pluck('name', 'key')->all())
                                ->placeholder(fn (): string => (string) $this->site()->theme?->name)
                                ->live(),
                            ColorPicker::make('primaryColour')
                                ->label(__('capell-admin::setup.colour'))
                                ->helperText(__('capell-admin::setup.colour_help'))
                                ->regex('/^#[0-9a-fA-F]{6}$/')
                                ->live(onBlur: true),
                            FileUpload::make('logo')
                                ->label(__('capell-admin::setup.logo'))
                                ->helperText(__('capell-admin::setup.logo_help'))
                                ->image()
                                ->acceptedFileTypes(['image/png', 'image/svg+xml'])
                                ->disk('local')
                                ->directory('guided-setup')
                                ->visibility('private'),
                        ]),
                    Step::make('pages')
                        ->label(__('capell-admin::setup.step_pages'))
                        ->schema([
                            CheckboxList::make('pageSlugs')
                                ->label(__('capell-admin::setup.step_pages'))
                                ->helperText(__('capell-admin::setup.pages_help'))
                                ->options(fn (): array => $this->pageOptions())
                                ->descriptions(fn (): array => $this->pageDescriptions())
                                ->live(),
                        ]),
                    Step::make('review')
                        ->label(__('capell-admin::setup.step_review'))
                        ->schema([
                            Text::make(fn (): string => $this->reviewBody()),
                        ]),
                ])
                    ->submitAction(Action::make('createPages')
                        ->label(__('capell-admin::setup.create_pages'))
                        ->submit('createPages')
                        ->color('primary'))
                    ->skippable(false),
            ]);
    }

    public function createPages(): void
    {
        $input = $this->input();
        $site = $this->site();
        $logo = $this->logoPath();

        try {
            CompleteGuidedSetupAction::run($input, $site, $logo);
        } catch (Throwable $throwable) {
            report($throwable);
            Notification::make()
                ->danger()
                ->title((string) __('capell-admin::setup.create_failed', ['reason' => '']))
                ->body($throwable->getMessage())
                ->persistent()
                ->send();

            return;
        }

        $this->finish($site);
    }

    public function skip(): void
    {
        SkipGuidedSetupAction::run($this->site());

        Notification::make()->success()->title((string) __('capell-admin::setup.skipped'))->send();

        $this->redirect(CapellDashboard::getUrl(), navigate: false);
    }

    public function publishAction(): Action
    {
        return Action::make('publish')
            ->label(__('capell-admin::setup.publish_site'))
            ->requiresConfirmation()
            ->modalHeading(__('capell-admin::setup.publish_site'))
            ->modalDescription(fn (): string => (string) __('capell-admin::setup.publish_confirm_body', [
                'count' => $this->createdPageCount,
                'url' => $this->publicUrl(),
            ]))
            ->modalSubmitActionLabel(__('capell-admin::setup.publish_site'))
            ->visible(fn (): bool => $this->created && ! $this->sitePublished)
            ->action(fn () => $this->publishSite());
    }

    /** Makes every page created by setup public, or says which page stopped it and leaves all of them private. */
    public function publishSite(): void
    {
        $actor = auth()->user();
        throw_if($actor === null, RuntimeException::class, 'A signed-in person is required to publish.');

        try {
            PublishSiteSpecSiteAction::run(BuildGuidedSetupSpecAction::run($this->input(), $this->site()), $this->site(), $actor);
        } catch (RuntimeException $runtimeException) {
            Notification::make()
                ->danger()
                ->title((string) __('capell-admin::setup.publish_failed', ['page' => $this->failedPageName($runtimeException)]))
                ->persistent()
                ->send();

            return;
        }

        $this->sitePublished = true;
        Notification::make()->success()->title((string) __('capell-admin::setup.published'))->send();
    }

    /** @return list<SiteSpecPresetData> */
    public function presets(): array
    {
        return resolve(SiteSpecPresetRegistry::class)->all();
    }

    /** @return array<string, string> slug => page name */
    public function pageOptions(): array
    {
        $options = [];

        foreach ($this->selectedPreset()?->spec['pages'] ?? [] as $page) {
            if (is_array($page) && is_string($page['slug'] ?? null)) {
                $options[$page['slug']] = (string) ($page['name'] ?? $page['slug']);
            }
        }

        return $options;
    }

    /** @return array<string, string> */
    public function pageDescriptions(): array
    {
        $descriptions = [];

        foreach ($this->selectedPreset()?->spec['pages'] ?? [] as $page) {
            if (is_array($page) && is_string($page['slug'] ?? null)) {
                $descriptions[$page['slug']] = (string) ($page['description'] ?? '');
            }
        }

        return $descriptions;
    }

    /**
     * What the preview sketch shows, from the answers so far.
     *
     * @return array{name: string, tagline: string, colour: string, menu: list<string>, intro: string}
     */
    public function previewData(): array
    {
        $preset = $this->selectedPreset();
        $options = $this->pageOptions();
        $chosen = array_values(array_unique(['home', ...array_map(strval(...), (array) ($this->data['pageSlugs'] ?? []))]));
        $intro = '';

        foreach ($preset instanceof SiteSpecPresetData ? $preset->spec['pages'] ?? [] : [] as $page) {
            if (is_array($page) && ($page['slug'] ?? null) === 'home') {
                $intro = (string) data_get($page, 'sections.0.content', '');
            }
        }

        $colour = (string) ($this->data['primaryColour'] ?? '');

        return [
            'name' => trim((string) ($this->data['siteName'] ?? '')),
            'tagline' => trim((string) ($this->data['tagline'] ?? '')),
            'colour' => preg_match('/^#[0-9a-fA-F]{6}$/', $colour) === 1 ? $colour : '',
            'menu' => array_values(array_filter(array_map(static fn (string $slug): ?string => $options[$slug] ?? null, $chosen))),
            'intro' => Str::limit(trim(strip_tags($intro)), 220),
        ];
    }

    public function reviewBody(): string
    {
        $appliers = resolve(SiteSpecApplierRegistry::class);
        $extras = ($appliers->has('navigation') ? __('capell-admin::setup.review_extra_menu') : '')
            . ($appliers->has('forms') && $this->presetHasForm() ? __('capell-admin::setup.review_extra_form') : '');

        return (string) __('capell-admin::setup.review_body', [
            'count' => count($this->selectedSlugs()),
            'extras' => $extras,
        ]);
    }

    /** @return array<Action> */
    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('skip')
                ->label(__('capell-admin::setup.skip'))
                ->color('gray')
                ->visible(fn (): bool => ! $this->created)
                ->action(fn () => $this->skip()),
        ];
    }

    private function presetHasForm(): bool
    {
        return isset($this->selectedPreset()?->spec['packageData']['forms']);
    }

    private function finish(Site $site): void
    {
        $home = Page::query()->where('site_id', $site->getKey())->homePage()->orderBy('id')->first();

        $this->created = true;
        $this->createdPageCount = count($this->selectedSlugs());
        $this->homeUrl = $home instanceof Page ? GetEditPageResourceUrlAction::run($home) : null;
    }

    /** @return list<string> */
    private function selectedSlugs(): array
    {
        return array_values(array_unique(['home', ...array_map(strval(...), (array) ($this->data['pageSlugs'] ?? []))]));
    }

    private function selectAllPages(): void
    {
        $this->data['pageSlugs'] = array_values(array_filter(array_keys($this->pageOptions()), static fn (string $slug): bool => $slug !== 'home'));
    }

    private function selectedPreset(): ?SiteSpecPresetData
    {
        $key = (string) ($this->data['presetKey'] ?? '');

        return $key === '' ? null : resolve(SiteSpecPresetRegistry::class)->get($key);
    }

    private function input(): GuidedSetupInputData
    {
        $state = $this->formSchema()->getState();

        return new GuidedSetupInputData(
            presetKey: (string) ($state['presetKey'] ?? ''),
            siteName: (string) ($state['siteName'] ?? ''),
            tagline: (string) ($state['tagline'] ?? ''),
            themeKey: filled($state['themeKey'] ?? null) ? (string) $state['themeKey'] : null,
            primaryColour: filled($state['primaryColour'] ?? null) ? (string) $state['primaryColour'] : null,
            pageSlugs: array_values(array_map(strval(...), (array) ($state['pageSlugs'] ?? []))),
        );
    }

    private function logoPath(): ?string
    {
        $logo = $this->data['logo'] ?? null;
        $logo = is_array($logo) ? (array_values($logo)[0] ?? null) : $logo;

        if ($logo instanceof TemporaryUploadedFile) {
            return $logo->getRealPath() ?: null;
        }

        return is_string($logo) && $logo !== '' ? storage_path('app/private/' . ltrim($logo, '/')) : null;
    }

    private function formSchema(): Schema
    {
        $schema = $this->getSchema('form');
        throw_unless($schema instanceof Schema, RuntimeException::class, 'The guided setup form is unavailable.');

        return $schema;
    }

    private function site(): Site
    {
        if (! $this->resolvedSite instanceof Site) {
            $site = $this->siteId === null ? null : Site::query()->whereKey($this->siteId)->first();
            abort_unless($site instanceof Site && SiteResource::canEdit($site), 403);
            $this->resolvedSite = $site;
        }

        return $this->resolvedSite;
    }

    private function defaultDomainUrl(): string
    {
        $domain = $this->site()->defaultDomain;

        return $domain instanceof SiteDomain ? $domain->full_url : (string) config('app.url');
    }

    private function publicUrl(): string
    {
        return $this->defaultDomainUrl();
    }

    /** @param list<SiteSpecPresetData> $presets */
    private function defaultPresetKey(array $presets, GuidedSetupStateData $state): string
    {
        foreach ($presets as $preset) {
            if ($state->suiteKey !== null && in_array($state->suiteKey, $preset->suiteKeys, true)) {
                return $preset->key;
            }
        }

        foreach ($presets as $preset) {
            if ($preset->key === 'service-business') {
                return $preset->key;
            }
        }

        return $presets === [] ? '' : $presets[0]->key;
    }

    private function failedPageName(RuntimeException $exception): string
    {
        return Str::of($exception->getMessage())->after(':')->before(',')->trim()->trim("'\"[]")->toString() ?: (string) __('capell-admin::setup.step_pages');
    }
}
