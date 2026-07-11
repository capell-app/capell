<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms;

use Closure;
use Filament\Forms\Components\Select;
use Illuminate\Contracts\Support\Arrayable;

class OptionMorphToSelectType
{
    protected ?string $label = null;

    /** @var array<int|string, mixed>|Arrayable<int|string, mixed>|Closure */
    protected array|Arrayable|Closure $options = [];

    protected ?Closure $getOptionsUsing = null;

    protected ?Closure $getSearchResultsUsing = null;

    protected ?Closure $getOptionLabelUsing = null;

    protected ?Closure $modifyKeySelectUsing = null;

    final public function __construct(protected string $alias) {}

    public static function make(string $alias): self
    {
        return resolve(self::class, ['alias' => $alias]);
    }

    public function label(?string $label): self
    {
        $this->label = $label;

        return $this;
    }

    /**
     * @param  array<int|string, mixed>|Arrayable<int|string, mixed>|Closure  $options
     */
    public function options(array|Arrayable|Closure $options): self
    {
        $this->options = $options;

        return $this;
    }

    public function getOptionsUsing(?Closure $callback): self
    {
        $this->getOptionsUsing = $callback;

        return $this;
    }

    public function getSearchResultsUsing(?Closure $callback): self
    {
        $this->getSearchResultsUsing = $callback;

        return $this;
    }

    public function getOptionLabelUsing(?Closure $callback): self
    {
        $this->getOptionLabelUsing = $callback;

        return $this;
    }

    public function modifyKeySelectUsing(?Closure $callback): self
    {
        $this->modifyKeySelectUsing = $callback;

        return $this;
    }

    public function getAlias(): string
    {
        return $this->alias;
    }

    public function getLabel(): string
    {
        return $this->label ?? (string) str($this->alias)->replace(['_', '-'], ' ')->ucfirst();
    }

    public function getModifyKeySelectUsingCallback(): ?Closure
    {
        return $this->modifyKeySelectUsing;
    }

    /**
     * @return array<int|string, mixed>
     */
    public function getOptions(Select $component): array
    {
        if ($this->getOptionsUsing instanceof Closure) {
            $resolvedOptions = $component->evaluate($this->getOptionsUsing);

            return is_array($resolvedOptions) ? $resolvedOptions : [];
        }

        return $this->resolveOptions($component);
    }

    /**
     * @return array<int|string, mixed>
     */
    public function getSearchResults(Select $component, string $search): array
    {
        if ($this->getSearchResultsUsing instanceof Closure) {
            $results = $component->evaluate($this->getSearchResultsUsing, ['search' => $search]);

            return is_array($results) ? $results : [];
        }

        $searchTerm = mb_strtolower($search);

        $filteredOptions = collect($this->resolveOptions($component))
            ->filter(fn (mixed $label, int|string $value): bool => str_contains(mb_strtolower((string) $label), $searchTerm)
                    || str_contains(mb_strtolower((string) $value), $searchTerm))
            ->sortBy(function (mixed $label, int|string $value) use ($searchTerm): array {
                $labelPosition = mb_stripos((string) $label, $searchTerm);
                $valuePosition = mb_stripos((string) $value, $searchTerm);

                $bestPosition = min(
                    $labelPosition === false ? PHP_INT_MAX : $labelPosition,
                    $valuePosition === false ? PHP_INT_MAX : $valuePosition,
                );

                return [
                    $bestPosition,
                    mb_strlen((string) $label),
                    (string) $label,
                ];
            });

        return $filteredOptions->all();
    }

    public function getOptionLabel(Select $component, int|string|null $value): ?string
    {
        if (in_array($value, [null, '', '0'], true)) {
            return null;
        }

        if ($this->getOptionLabelUsing instanceof Closure) {
            $label = $component->evaluate($this->getOptionLabelUsing, ['value' => $value]);

            return is_string($label) ? $label : null;
        }

        $options = $this->resolveOptions($component);

        $stringValue = (string) $value;

        if (array_key_exists($value, $options)) {
            return (string) $options[$value];
        }

        if (array_key_exists($stringValue, $options)) {
            return (string) $options[$stringValue];
        }

        return $stringValue;
    }

    /**
     * @return array<int|string, mixed>
     */
    private function resolveOptions(Select $component): array
    {
        $resolvedOptions = $this->options;

        if ($resolvedOptions instanceof Closure) {
            $resolvedOptions = $component->evaluate($resolvedOptions);
        }

        if ($resolvedOptions instanceof Arrayable) {
            $resolvedOptions = $resolvedOptions->toArray();
        }

        return is_array($resolvedOptions) ? $resolvedOptions : [];
    }
}
