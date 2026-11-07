<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms;

use Capell\Admin\Contracts\Support\FlagIconRenderer;
use Capell\Admin\Enums\TranslationTabsOrientation;
use Capell\Admin\Support\Loader\LanguageLoader;
use Capell\Core\Models\Language;
use Closure;
use Filament\Forms\Components\Field;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Override;

/**
 * Edits one group of fields once per language.
 *
 * Each field is cloned for every language and bound to `{field}.{locale}`, the
 * shape Spatie translatable JSON columns use, so one declaration yields
 * `name.en`, `name.fr` and so on. Tabs sit across the top by default or down
 * the side via orientation().
 */
class TranslatableFields extends Tabs
{
    /** @var array<int, Component>|Closure */
    protected array|Closure $translatableFields = [];

    /** @var Collection<int, Language>|Closure|null */
    protected Collection|Closure|null $languages = null;

    protected TranslationTabsOrientation|Closure|null $orientation = null;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->contained()
            ->vertical(fn (): bool => $this->getOrientation()->isVertical())
            ->components(fn (): array => $this->buildLanguageTabs());
    }

    /**
     * @param  array<int, Component>|Closure  $fields
     */
    public function fields(array|Closure $fields): static
    {
        $this->translatableFields = $fields;

        return $this;
    }

    /**
     * @param  Collection<int, Language>|Closure|null  $languages
     */
    public function languages(Collection|Closure|null $languages): static
    {
        $this->languages = $languages;

        return $this;
    }

    public function orientation(TranslationTabsOrientation|Closure|null $orientation): static
    {
        $this->orientation = $orientation;

        return $this;
    }

    public function getOrientation(): TranslationTabsOrientation
    {
        $orientation = $this->evaluate($this->orientation);

        if (is_string($orientation)) {
            $orientation = TranslationTabsOrientation::tryFrom($orientation);
        }

        return $orientation instanceof TranslationTabsOrientation ? $orientation : TranslationTabsOrientation::configured();
    }

    public function verticalTabs(bool $condition = true): static
    {
        return $this->orientation($condition ? TranslationTabsOrientation::Vertical : TranslationTabsOrientation::Horizontal);
    }

    /**
     * @return Collection<int, Language>
     */
    public function getLanguages(): Collection
    {
        $languages = $this->evaluate($this->languages);

        if (is_array($languages)) {
            $languages = collect($languages);
        }

        return $languages instanceof Collection ? $languages : LanguageLoader::languages(null);
    }

    /**
     * @return array<int, Tab>
     */
    private function buildLanguageTabs(): array
    {
        $fields = (array) $this->evaluate($this->translatableFields);
        $flagRenderer = resolve(FlagIconRenderer::class);
        // Two groups in one form must not share tab keys, so the key carries the
        // state path and a stable fingerprint of the fields the group edits.
        $fingerprint = hash('xxh3', $this->fingerprint($fields));
        $keyPrefix = 'translatable-fields::' . ($this->getStatePath() ?? 'root') . '::' . $fingerprint;

        return $this->getLanguages()
            ->map(fn (Language $language): Tab => Tab::make(new HtmlString(
                '<span aria-hidden="true">' . $flagRenderer->render('flag-4x3-' . $language->flag) . '</span> ' . e($language->name),
            ))
                ->key($keyPrefix . '::' . $language->code, isInheritable: false)
                ->schema($this->localise($fields, $language)))
            ->values()
            ->all();
    }

    /**
     * Clones the components for one language, rebinding every field to
     * `{its own path}.{locale}`. Layout components are walked so a Grid or
     * Section does not leave its fields sharing one state path.
     *
     * @param  array<int, mixed>  $components
     * @return array<int, Component>
     */
    private function localise(array $components, Language $language): array
    {
        $localised = [];

        foreach ($components as $component) {
            if (! $component instanceof Component) {
                continue;
            }

            $clone = $component->getClone();

            if ($clone instanceof Field) {
                $clone->statePath(($clone->getStatePath(false) ?? $clone->getName()) . '.' . (filled($language->locale) ? $language->locale : $language->code))
                    ->validationAttribute(fn (Field $component): string => lcfirst(strip_tags($this->labelText($component->getLabel()))) . ' (' . $language->name . ')');
            } else {
                $children = $clone->getDefaultChildComponents();

                if (is_array($children) && $children !== []) {
                    $clone->components($this->localise($children, $language));
                }
            }

            $localised[] = $clone;
        }

        return $localised;
    }

    /**
     * @param  array<int, mixed>  $components
     */
    private function fingerprint(array $components): string
    {
        $parts = [];

        foreach ($components as $component) {
            if ($component instanceof Field) {
                $parts[] = $component->getName();
            } elseif ($component instanceof Component) {
                $children = $component->getDefaultChildComponents();
                $parts[] = $component::class . '[' . (is_array($children) ? $this->fingerprint($children) : '') . ']';
            }
        }

        return implode(',', $parts);
    }

    private function labelText(Htmlable|string|null $label): string
    {
        return $label instanceof Htmlable ? $label->toHtml() : (string) $label;
    }
}
