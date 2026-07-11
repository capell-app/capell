<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms;

use Closure;
use Filament\Forms\Components\Concerns\CanAllowHtml;
use Filament\Forms\Components\Concerns\CanBeMarkedAsRequired;
use Filament\Forms\Components\Concerns\CanBeNative;
use Filament\Forms\Components\Concerns\CanBePreloaded;
use Filament\Forms\Components\Concerns\CanBeSearchable;
use Filament\Forms\Components\Concerns\HasLoadingMessage;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Concerns\HasLabel;
use Filament\Schemas\Components\Concerns\HasName;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Concerns\CanBeContained;
use Illuminate\Contracts\Support\Htmlable;
use InvalidArgumentException;
use Override;

class OptionMorphToSelect extends Component
{
    use CanAllowHtml;
    use CanBeContained;
    use CanBeMarkedAsRequired;
    use CanBeNative;
    use CanBePreloaded;
    use CanBeSearchable;
    use HasLabel {
        getLabel as getBaseLabel;
    }
    use HasLoadingMessage;
    use HasName;

    protected string $view = 'filament-schemas::components.fieldset';

    protected bool|Closure $isRequired = false;

    protected int|Closure $optionsLimit = 50;

    /**
     * @var array<OptionMorphToSelectType>|Closure
     */
    protected array|Closure $types = [];

    protected ?Closure $modifyTypeSelectUsing = null;

    protected ?Closure $modifyKeySelectUsing = null;

    protected bool|Closure $hasTypeSelectToggleButtons = false;

    final public function __construct(string $name)
    {
        $this->name($name);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->schema(function (self $component): array {
            $typeColumn = $component->getTypeStatePath();
            $keyColumn = $component->getKeyStatePath();

            $types = $component->getTypes();
            $isRequired = $component->isRequired();

            $selectedTypeKey = $component->getRawState()[$typeColumn] ?? null;
            $selectedType = is_string($selectedTypeKey) ? ($types[$selectedTypeKey] ?? null) : null;

            $typeSelect = $component->hasTypeSelectToggleButtons()
                ? ToggleButtons::make($typeColumn)
                    ->label($component->getLabel())
                    ->hiddenLabel()
                    ->options(array_map(
                        static fn (OptionMorphToSelectType $type): string => $type->getLabel(),
                        $types,
                    ))
                    ->inline()
                    ->required($isRequired)
                    ->live()
                    ->afterStateUpdated(function (Set $set) use ($component, $keyColumn): void {
                        $set($keyColumn, null);
                        $component->callAfterStateUpdatedForChildComponent();
                    })
                : Select::make($typeColumn)
                    ->label($component->getLabel())
                    ->hiddenLabel()
                    ->options(array_map(
                        static fn (OptionMorphToSelectType $type): string => $type->getLabel(),
                        $types,
                    ))
                    ->native($component->isNative())
                    ->required($isRequired)
                    ->live()
                    ->afterStateUpdated(function (Set $set) use ($component, $keyColumn): void {
                        $set($keyColumn, null);
                        $component->callAfterStateUpdatedForChildComponent();
                    });

            $keySelect = Select::make($keyColumn)
                ->label(fn (Get $get): ?string => ($types[$get($typeColumn)] ?? null)?->getLabel())
                ->hiddenLabel()
                ->native(false)
                ->options(function (Select $select, Get $get) use ($typeColumn, $types): ?array {
                    $typeAlias = $get($typeColumn);

                    if (! is_string($typeAlias) || ! isset($types[$typeAlias])) {
                        return null;
                    }

                    $type = $types[$typeAlias];
                    $resolvedOptions = $type->getOptions($select);

                    return $this->normalizeSelectedStateIntoOptions($resolvedOptions, $select->getState());
                })
                ->dynamicOptions(fn (Select $select): ?bool => $select->isPreloaded() ? null : false)
                ->getSearchResultsUsing(function (Select $select, Get $get, string $search) use ($typeColumn, $types): ?array {
                    $typeAlias = $get($typeColumn);

                    if (! is_string($typeAlias) || ! isset($types[$typeAlias])) {
                        return null;
                    }

                    $type = $types[$typeAlias];
                    $results = $type->getSearchResults($select, $search);

                    return $this->limitOptions($results, $select->getOptionsLimit());
                })
                ->getOptionLabelUsing(function (Select $select, Get $get, int|string|null $value) use ($typeColumn, $types): ?string {
                    $typeAlias = $get($typeColumn);

                    if (! is_string($typeAlias) || ! isset($types[$typeAlias])) {
                        return null;
                    }

                    return $types[$typeAlias]->getOptionLabel($select, $value);
                })
                ->native($component->isNative())
                ->required(fn (Get $get): bool => filled(($types[$get($typeColumn)] ?? null)))
                ->hidden(fn (Get $get): bool => blank(($types[$get($typeColumn)] ?? null)))
                ->dehydratedWhenHidden()
                ->searchable($component->isSearchable())
                ->searchDebounce($component->getSearchDebounce())
                ->searchPrompt($component->getSearchPrompt())
                ->searchingMessage($component->getSearchingMessage())
                ->noOptionsMessage($component->getNoOptionsMessage())
                ->noSearchResultsMessage($component->getNoSearchResultsMessage())
                ->loadingMessage($component->getLoadingMessage())
                ->allowHtml($component->isHtmlAllowed())
                ->optionsLimit($component->getOptionsLimit())
                ->preload($component->isPreloaded())
                ->when(
                    $component->isLive(),
                    fn (Select $select): Select => $select->live(onBlur: $this->isLiveOnBlur()),
                )
                ->afterStateUpdated(function () use ($component): void {
                    $component->callAfterStateUpdatedForChildComponent();
                });

            if (($callback = $component->getModifyTypeSelectUsingCallback()) instanceof Closure) {
                $typeSelect = $component->evaluate($callback, [
                    'select' => $typeSelect,
                    'toggleButtons' => $typeSelect,
                ]) ?? $typeSelect;
            }

            if (($callback = $component->getModifyKeySelectUsingCallback()) instanceof Closure) {
                $keySelect = $component->evaluate($callback, [
                    'select' => $keySelect,
                ]) ?? $keySelect;
            }

            $callback = $selectedType?->getModifyKeySelectUsingCallback();

            if ($callback instanceof Closure) {
                $keySelect = $component->evaluate($callback, [
                    'select' => $keySelect,
                ]) ?? $keySelect;
            }

            return [$typeSelect, $keySelect];
        });
    }

    public static function make(?string $name = null): static
    {
        $componentClass = static::class;

        $name ??= static::getDefaultName();

        throw_if(blank($name), InvalidArgumentException::class, sprintf('OptionMorphToSelect of class [%s] must have a unique name, passed to the [make()] method.', $componentClass));

        $static = resolve($componentClass, ['name' => $name]);
        $static->configure();

        return $static;
    }

    public static function getDefaultName(): ?string
    {
        return null;
    }

    public function modifyTypeSelectUsing(?Closure $callback): static
    {
        $this->modifyTypeSelectUsing = $callback;

        return $this;
    }

    public function modifyKeySelectUsing(?Closure $callback): static
    {
        $this->modifyKeySelectUsing = $callback;

        return $this;
    }

    public function getModifyTypeSelectUsingCallback(): ?Closure
    {
        return $this->modifyTypeSelectUsing;
    }

    public function getModifyKeySelectUsingCallback(): ?Closure
    {
        return $this->modifyKeySelectUsing;
    }

    public function typeSelectToggleButtons(bool|Closure $condition = true): static
    {
        $this->hasTypeSelectToggleButtons = $condition;

        return $this;
    }

    public function hasTypeSelectToggleButtons(): bool
    {
        return (bool) $this->evaluate($this->hasTypeSelectToggleButtons);
    }

    public function optionsLimit(int|Closure $limit): static
    {
        $this->optionsLimit = $limit;

        return $this;
    }

    public function required(bool|Closure $condition = true): static
    {
        $this->isRequired = $condition;

        return $this;
    }

    /**
     * @param  array<OptionMorphToSelectType>|Closure  $types
     */
    public function types(array|Closure $types): static
    {
        $this->types = $types;

        return $this;
    }

    /**
     * @return array<string, OptionMorphToSelectType>
     */
    public function getTypes(): array
    {
        $types = [];

        foreach ($this->evaluate($this->types) as $type) {
            $types[$type->getAlias()] = $type;
        }

        return $types;
    }

    public function isRequired(): bool
    {
        return (bool) $this->evaluate($this->isRequired);
    }

    public function getOptionsLimit(): int
    {
        return $this->evaluate($this->optionsLimit);
    }

    public function getLabel(): string|Htmlable|null
    {
        if (filled($label = $this->getBaseLabel())) {
            return $label;
        }

        $label = (string) str($this->getName())
            ->afterLast('.')
            ->kebab()
            ->replace(['-', '_'], ' ')
            ->ucfirst();

        return $this->shouldTranslateLabel ? __($label) : $label;
    }

    public function callAfterStateUpdatedForChildComponent(bool $shouldBubbleToParents = true): static
    {
        return parent::callAfterStateUpdated($shouldBubbleToParents);
    }

    #[Override]
    public function callAfterStateUpdated(bool $shouldBubbleToParents = true): static
    {
        if ($shouldBubbleToParents) {
            $this->getContainer()->getParentComponent()?->callAfterStateUpdated();
        }

        return $this;
    }

    protected function getTypeStatePath(): string
    {
        return $this->getMorphNamePrefix() . '_type';
    }

    protected function getKeyStatePath(): string
    {
        return $this->getMorphNamePrefix() . '_id';
    }

    protected function getMorphNamePrefix(): string
    {
        return (string) str($this->getName())->afterLast('.');
    }

    /**
     * @param  array<int|string, string>  $options
     * @param  int|string|array<int|string>|null  $state
     * @return array<int|string, string>
     */
    private function normalizeSelectedStateIntoOptions(array $options, int|string|array|null $state): array
    {
        if (is_array($state)) {
            foreach ($state as $selectedValue) {
                $selectedValue = (string) $selectedValue;

                if ($selectedValue !== '' && ! array_key_exists($selectedValue, $options)) {
                    $options[$selectedValue] = $selectedValue;
                }
            }

            return $options;
        }

        if (in_array($state, [null, '', '0'], true)) {
            return $options;
        }

        $selectedValue = (string) $state;

        if (! array_key_exists($state, $options) && ! array_key_exists($selectedValue, $options)) {
            $options[$selectedValue] = $selectedValue;
        }

        return $options;
    }

    /**
     * @param  array<int|string, string>  $options
     * @return array<int|string, string>
     */
    private function limitOptions(array $options, int $limit): array
    {
        if (count($options) <= $limit) {
            return $options;
        }

        return array_slice($options, 0, $limit, true);
    }
}
