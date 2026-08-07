<?php

declare(strict_types=1);

namespace Capell\Core\Data;

use Closure;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Database\Eloquent\Model;
use Spatie\LaravelData\Data;

final class PageTypeData extends Data implements HasLabel
{
    /**
     * @param  class-string<Model>|null  $model  The model this type resolves to, or null
     *                                           when the type cannot be resolved — see
     *                                           {@see self::unavailableSubject()}. Every
     *                                           type registered through
     *                                           `registerPageType()` has a model; only
     *                                           casts of orphaned rows produce null.
     */
    public function __construct(
        public string $name,
        public ?string $model,
        public null|string|Closure $label = null,
    ) {}

    /**
     * A blueprint row whose subject no installed package registers.
     *
     * Uninstalling a package leaves its blueprint rows behind on purpose, so
     * listing surfaces must be able to render them well enough for an operator
     * to reinstall the package or delete the rows.
     */
    public static function unavailableSubject(string $key): self
    {
        return new self(
            name: $key,
            model: null,
            label: __('capell::type.unavailable_subject', ['key' => $key]),
        );
    }

    public function isAvailable(): bool
    {
        return $this->model !== null;
    }

    public function getLabel(): string
    {
        if (is_string($this->label)) {
            return $this->label;
        }

        if (is_callable($this->label)) {
            return (string) call_user_func($this->label);
        }

        return str($this->name)->studly()->plural()->toString();
    }

    public function getKey(): string
    {
        return str($this->name)->studly()->plural()->toString();
    }

    public function getComponentName(): string
    {
        return ucfirst($this->name);
    }
}
