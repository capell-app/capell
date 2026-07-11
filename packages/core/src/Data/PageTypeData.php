<?php

declare(strict_types=1);

namespace Capell\Core\Data;

use Closure;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Database\Eloquent\Model;
use Spatie\LaravelData\Data;

class PageTypeData extends Data implements HasLabel
{
    public function __construct(
        public string $name,
        /** @var class-string<Model> */
        public string $model,
        public null|string|Closure $label = null,
    ) {}

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
