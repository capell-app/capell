<?php

declare(strict_types=1);

namespace Capell\Admin\Concerns;

use Capell\Admin\Data\WelcomeTourStepData;
use Closure;
use Illuminate\Support\HtmlString;
use Illuminate\View\View;

trait HasWelcomeTours
{
    /** @var array<string, WelcomeTourStepData> */
    private array $welcomeTourSteps = [];

    public function registerWelcomeTourStep(
        string $key,
        string|Closure $title,
        string|Closure|HtmlString|View $description,
        ?string $element = null,
        ?string $icon = null,
        ?string $iconColor = null,
        int $sort = 100,
        bool|Closure $visible = true,
    ): void {
        if ($key === '') {
            return;
        }

        $this->welcomeTourSteps[$key] = new WelcomeTourStepData(
            key: $key,
            title: $title,
            description: $description,
            element: $element,
            icon: $icon,
            iconColor: $iconColor,
            sort: $sort,
            visible: $visible,
        );
    }

    /**
     * @return list<WelcomeTourStepData>
     */
    public function getWelcomeTourSteps(): array
    {
        return array_values(collect($this->welcomeTourSteps)
            ->filter(fn (WelcomeTourStepData $step): bool => $step->isVisible())
            ->sortBy([
                ['sort', 'asc'],
                ['key', 'asc'],
            ])
            ->values()
            ->all());
    }

    public function clearWelcomeTourSteps(): void
    {
        $this->welcomeTourSteps = [];
    }
}
