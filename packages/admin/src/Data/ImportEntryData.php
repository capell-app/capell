<?php

declare(strict_types=1);

namespace Capell\Admin\Data;

use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Spatie\LaravelData\Data;

final class ImportEntryData extends Data
{
    /**
     * @param  list<class-string>  $pageClasses
     * @param  (Closure(): (Action|ActionGroup))  $actionFactory
     * @param  (Closure(): bool)|null  $authorize
     */
    public function __construct(
        public string $key,
        public string $labelKey,
        public ?string $descriptionKey,
        public string $icon,
        public int $sort,
        public array $pageClasses,
        public Closure $actionFactory,
        public ?Closure $authorize = null,
    ) {}

    public function isVisible(): bool
    {
        if (! $this->authorize instanceof Closure) {
            return true;
        }

        return (bool) ($this->authorize)();
    }

    public function makeAction(): Action|ActionGroup
    {
        $action = ($this->actionFactory)()
            ->label(__($this->labelKey))
            ->icon($this->icon);

        if ($this->descriptionKey !== null && $action instanceof Action) {
            $action->tooltip(__($this->descriptionKey));
        }

        return $action;
    }
}
