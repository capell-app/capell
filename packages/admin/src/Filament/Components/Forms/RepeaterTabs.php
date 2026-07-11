<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms;

use Capell\Admin\Actions\TranslateTextAction;
use Capell\Core\Models\Language;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Concerns\CanBeCompact;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\ExposedLivewireMethod;
use Filament\Support\Concerns\CanBeContained;
use Filament\Support\Concerns\HasExtraAlpineAttributes;
use Filament\Support\Enums\Size;
use Illuminate\Support\Str;
use Override;
use RuntimeException;

class RepeaterTabs extends Repeater
{
    use CanBeCompact;
    use CanBeContained;
    use HasExtraAlpineAttributes;

    public null|int|Closure $activeTab = null;

    /** @var array<int, mixed>|Closure */
    protected array|Closure $createItems = [];

    protected string|int|Closure|null $itemBadge = null;

    protected string|int|Closure|null $itemIcon = null;

    protected string|int|Closure|null $tabQueryStringKey = null;

    protected string $view = 'capell-admin::components.schemas.repeater-tabs';

    private ?Closure $beforeAddAction = null;

    private Closure|bool $minimal = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setupActiveTabAfterStateHydrated()
            ->registerActions([
                fn (self $component): Action => $component->getAddAllAction(),
                fn (self $component): Action => $component->translateAction(),
                fn (self $component): Action => $component->cloneItemAction(),
                fn (self $component): Action => $component->deleteItemAction(),
            ]);
    }

    public function setupActiveTabAfterStateHydrated(): self
    {
        $afterStateHydrated = $this->getAfterStateHydrated();

        return $this->afterStateHydrated(function (RepeaterTabs $component) use ($afterStateHydrated): void {
            if ($afterStateHydrated instanceof Closure) {
                $afterStateHydrated($component);
            }

            $livewire = $component->getLivewire();

            $containerStatePath = $component->getContainer()->getStatePath();

            data_set($livewire, $containerStatePath . '.activeTab', $component->getActiveTab());
        });
    }

    /**
     * @param  array<int, mixed>|Closure|null  $items
     */
    public function createItems(array|Closure|null $items): static
    {
        $this->createItems = $items ?? [];

        return $this;
    }

    /**
     * @return array<int, mixed>
     */
    public function getCreateItems(): array
    {
        return (array) $this->evaluate($this->createItems);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function resolveAddActionData(array $arguments): array
    {
        if ($arguments !== []) {
            return $arguments;
        }

        $requestLanguageId = request()->query('language_id');

        if ($this->canCreateLanguage($requestLanguageId)) {
            return [
                'language_id' => (int) $requestLanguageId,
            ];
        }

        $createItems = $this->getCreateItems();

        if (count($createItems) !== 1) {
            return [];
        }

        $languageId = $createItems[array_key_first($createItems)]['id'] ?? null;

        if (! is_int($languageId) && ! (is_string($languageId) && is_numeric($languageId))) {
            return [];
        }

        return [
            'language_id' => (int) $languageId,
        ];
    }

    public function getParentComponent(): ?Component
    {
        return $this->getContainer()->getParentComponent();
    }

    public function activeTab(int|Closure $activeTab): static
    {
        $this->activeTab = $activeTab;

        return $this;
    }

    public function beforeAddAction(Closure $beforeAddAction): static
    {
        $this->beforeAddAction = $beforeAddAction;

        return $this;
    }

    public function getDefaultTab(): ?int
    {
        if ($this->isTabPersistedInQueryString()) {
            $queryStringTab = request()->query($this->getTabQueryStringKey());

            $index = 0;
            foreach (array_keys($this->getChildSchemas()) as $uuid) {
                $index++;
                if ($uuid !== $queryStringTab) {
                    continue;
                }

                return $index;
            }
        }

        return 1;
    }

    public function getActiveTab(): int
    {
        if ($this->activeTab === null) {
            $this->activeTab = $this->getDefaultTab();
        }

        $parentStatePath = $this->getContainer()->getStatePath();
        $livewire = $this->getLivewire();

        $activeTab = data_get($livewire, $parentStatePath . '.activeTab');

        return $activeTab ?? $this->evaluate($this->activeTab);
    }

    #[Override]
    public function getAddAction(): Action
    {
        $action = parent::getAddAction()
            ->label(fn (array $arguments): string => $arguments['label'] ?? __('capell-admin::generic.add_tab'))
            ->visible(fn (): bool => $this->isAddable())
            ->color('gray')
            ->groupedIcon(fn (array $arguments): string => $arguments['icon'] ?? 'heroicon-s-plus')
            ->size(Size::Small)
            ->action(function (RepeaterTabs $component, array $arguments): void {
                $data = $component->resolveAddActionData($arguments);

                $state = (array) $component->getState();

                if ($component->callBeforeAddAction($state, $data) === false) {
                    return;
                }

                $statePath = $component->getStatePath();

                $livewire = $component->getLivewire();

                $newUuid = $component->generateUuid();

                $state[$newUuid] = $data;

                $tabId = count($state);

                $component->state($state);

                $childContainer = $component->getChildSchema($newUuid ?? array_key_last($state));
                throw_if(! $childContainer instanceof Schema, RuntimeException::class, 'Repeater tab child schema could not be resolved.');

                $childContainer->fill();

                if ($data !== []) {
                    $childContainer->fill($data);
                }

                $component->collapsed(false, shouldMakeComponentCollapsible: false);

                $livewire->dispatch('refresh-tabs', tabId: $tabId, statePath: $statePath);

                $component->callAfterStateUpdated();
            });

        if ($this->modifyAddActionUsing instanceof Closure) {
            return $this->evaluate($this->modifyAddActionUsing, [
                'action' => $action,
            ]) ?? $action;
        }

        return $action;
    }

    public function getAddAllAction(): Action
    {
        $action = Action::make('add-all')
            ->label(__('capell-admin::button.all_languages'))
            ->visible(
                fn (RepeaterTabs $component): bool => $component->isAddable()
                    && count($component->getCreateItems()) > 1,
            )
            ->color('gray')
            ->grouped()
            ->groupedIcon(fn (array $arguments): string => $arguments['icon'] ?? 'heroicon-s-plus')
            ->size(Size::Small)
            ->action(function (RepeaterTabs $component): void {
                $languages = $component->getCreateItems();

                $state = (array) $component->getState();
                $statePath = $component->getStatePath();
                $livewire = $component->getLivewire();

                foreach ($languages as $language) {
                    $data = [
                        'language_id' => $language['id'],
                    ];

                    if ($component->callBeforeAddAction($state, $data) === false) {
                        return;
                    }

                    $newUuid = $component->generateUuid();

                    $state[$newUuid] = $data;

                    $component->state($state);

                    $childContainer = $component->getChildSchema($newUuid ?? array_key_last($state));
                    throw_if(! $childContainer instanceof Schema, RuntimeException::class, 'Repeater tab child schema could not be resolved.');

                    $childContainer->fill();

                    $childContainer->fill($data);

                    $state = $component->getState();
                }

                $component->collapsed(false, shouldMakeComponentCollapsible: false);

                $livewire->dispatch('refresh-tabs', tabId: 1, statePath: $statePath);

                $component->callAfterStateUpdated();
            });

        if ($this->modifyAddActionUsing instanceof Closure) {
            return $this->evaluate($this->modifyAddActionUsing, [
                'action' => $action,
            ]) ?? $action;
        }

        return $action;
    }

    public function getItemBadge(string|int $uuid): mixed
    {
        $childSchema = $this->getChildSchema($uuid);
        throw_if(! $childSchema instanceof Schema, RuntimeException::class, 'Repeater tab child schema could not be resolved.');

        return $this->evaluate($this->itemBadge, [
            'state' => $childSchema->getRawState(),
            'uuid' => $uuid,
        ]);
    }

    public function getItemIcon(string|int $uuid): ?string
    {
        $childSchema = $this->getChildSchema($uuid);
        throw_if(! $childSchema instanceof Schema, RuntimeException::class, 'Repeater tab child schema could not be resolved.');

        return $this->evaluate($this->itemIcon, [
            'state' => $childSchema->getRawState(),
            'uuid' => $uuid,
        ]);
    }

    public function getTabQueryStringKey(): ?string
    {
        return $this->evaluate($this->tabQueryStringKey);
    }

    #[Override]
    public function getView(): string
    {
        if ($this->isMinimal()) {
            return 'capell-admin::components.schemas.repeater-tabs-minimal';
        }

        return parent::getView();
    }

    public function isMinimal(): bool
    {
        return $this->evaluate($this->minimal);
    }

    public function isTabPersistedInQueryString(): bool
    {
        return filled($this->getTabQueryStringKey());
    }

    public function itemBadge(string|int|Closure|null $badge): static
    {
        $this->itemBadge = $badge;

        return $this;
    }

    public function itemIcon(string|int|Closure|null $icon): static
    {
        $this->itemIcon = $icon;

        return $this;
    }

    public function minimal(bool|Closure $minimal): static
    {
        $this->minimal = $minimal;

        return $this;
    }

    public function persistTabInQueryString(string|int|Closure|null $key = 'tab'): static
    {
        $this->tabQueryStringKey = $key;

        return $this;
    }

    /**
     * @param  array<int, mixed>|Closure  $tabs
     */
    public function tabs(array|Closure $tabs): static
    {
        $this->childComponents($tabs);

        return $this;
    }

    #[ExposedLivewireMethod]
    public function cloneRepeaterTab(?int $tab = null, ?int $languageId = null): void
    {
        $livewire = $this->getLivewire();
        $state = $this->getState();
        $statePath = $this->getStatePath();

        $stateKeys = array_keys($state);
        $statePosition = $tab - 1;
        $uuidToDuplicate = $stateKeys[$statePosition];

        $data = data_get($livewire, sprintf('%s.%s', $statePath, $uuidToDuplicate));
        if ($languageId !== null && $languageId !== 0) {
            $data['language_id'] = $languageId;
        }

        $newUuid = (string) Str::uuid();

        $state[$newUuid] = $data;

        $newTab = count($state);

        $this->state($state);

        $this->getChildSchemas()[$newUuid]->fill();

        if ($data !== []) {
            $this->getChildSchemas()[$newUuid]->fill($data);
        }

        $this->collapsed(false, shouldMakeComponentCollapsible: false);

        $this->callAfterStateUpdated();

        // Activate next tab
        $livewire->dispatch('refresh-tabs', tabId: $newTab, statePath: $statePath);
    }

    #[ExposedLivewireMethod]
    public function deleteRepeaterTab(?int $tab = null): void
    {
        $livewire = $this->getLivewire();
        $state = $this->getState();
        $statePath = $this->getStatePath();

        $stateKeys = array_keys($state);
        $statePosition = $tab - 1;

        unset($state[$stateKeys[$statePosition]]);

        $tabId = isset($stateKeys[$statePosition + 1]) ? $statePosition + 1 : count($state);

        $this->state($state);

        $this->callAfterStateUpdated();

        if ($tabId !== 0) {
            $livewire->dispatch('refresh-tabs', tabId: $tabId, statePath: $statePath);
        }
    }

    public function translateAction(): Action
    {
        return Action::make('translate')
            ->label(__('capell-admin::button.auto_translate'))
            ->tooltip(__('capell-admin::generic.auto_translate_info'))
            ->color('gray')
            ->icon('heroicon-o-sparkles')
            ->size(Size::Small)
            ->requiresConfirmation()
            ->grouped()
            ->visible(config('capell-admin.auto_translate_language_text', true))
            ->modalHeading(__('capell-admin::generic.auto_translate'))
            ->modalDescription(__('capell-admin::generic.auto_translate_confirm'))
            ->action(function (RepeaterTabs $component): void {
                $statePath = $component->getStatePath();
                $livewire = $component->getLivewire();
                $tab = $component->getActiveTab();

                $state = $component->getState();

                $stateKeys = array_keys($state);
                $statePosition = $tab - 1;
                $currentTab = $stateKeys[$statePosition];

                $currentData = $state[$currentTab];

                $currentLanguage = Language::query()->find($currentData['language_id'] ?? null);

                if (! $currentLanguage instanceof Language) {
                    return;
                }

                $currentLocale = $currentLanguage->code;

                foreach ($state as $uuid => $data) {
                    if ($uuid === $currentTab) {
                        continue;
                    }

                    $language = Language::query()->find($data['language_id'] ?? null);

                    if (! $language instanceof Language) {
                        continue;
                    }

                    $locale = $language->code;

                    foreach ($currentData as $name => $value) {
                        if ($name === 'language_id') {
                            continue;
                        }

                        if ($value === null) {
                            continue;
                        }

                        if ($value === '') {
                            continue;
                        }

                        if (is_array($value) && $value === []) {
                            continue;
                        }

                        if (is_array($value)) {
                            foreach ($value as $key => $meta) {
                                if (! is_string($meta)) {
                                    continue;
                                }

                                $data[$name][$key] = TranslateTextAction::run($meta, $locale, $currentLocale);
                            }

                            continue;
                        }

                        if (! is_string($value)) {
                            continue;
                        }

                        $data[$name] = TranslateTextAction::run($value, $locale, $currentLocale);
                    }

                    if ((! isset($data['meta']['slug']) || blank($data['meta']['slug'])) && isset($data['title']) && $data['title'] !== '') {
                        $data['meta']['slug'] = Str::slug($data['title']);
                    }

                    $state[$uuid] = $data;
                }

                $component->state($state);

                $component->callAfterStateUpdated();

                $livewire->dispatch('repeater::translate', statePath: $statePath, tab: 1);
            });
    }

    public function cloneItemAction(): Action
    {
        return Action::make('cloneItem')
            ->label(__('capell-admin::button.clone'))
            ->color('gray')
            ->grouped()
            ->livewireClickHandlerEnabled(false)
            ->alpineClickHandler(
                function (array $arguments): string {
                    $languageId = $arguments['language_id'] ?? 'null';

                    return <<<JS
                \$wire.callSchemaComponentMethod(
                    '{$arguments['key']}',
                    'cloneRepeaterTab',
                    {
                        tab: tab,
                        languageId : {$languageId},
                    },
                )
                JS;
                },
            );
    }

    public function deleteItemAction(): Action
    {
        return Action::make('deleteItem')
            ->label(__('filament-forms::components.repeater.actions.delete.label'))
            ->icon('heroicon-m-trash')
            ->livewireClickHandlerEnabled(false)
            ->grouped()
            ->color('danger')
            ->alpineClickHandler(
                fn (array $arguments): string => <<<JS
                \$wire.callSchemaComponentMethod(
                    '{$arguments['key']}',
                    'deleteRepeaterTab',
                    {
                        tab: tab
                    },
                )
                JS
            );
    }

    public function getAfterStateHydrated(): ?Closure
    {
        return $this->afterStateHydrated;
    }

    private function canCreateLanguage(mixed $languageId): bool
    {
        if (! is_int($languageId) && ! (is_string($languageId) && is_numeric($languageId))) {
            return false;
        }

        return collect($this->getCreateItems())
            ->contains(fn (mixed $item): bool => is_array($item) && (int) ($item['id'] ?? 0) === (int) $languageId);
    }

    /**
     * @param  array<int|string, mixed>  $state
     * @param  array<string, mixed>  $data
     */
    private function callBeforeAddAction(array $state, array $data): bool
    {
        if (! $this->beforeAddAction instanceof Closure) {
            return true;
        }

        return $this->evaluate($this->beforeAddAction, [
            'data' => $data,
            'state' => $state,
        ]);
    }
}
