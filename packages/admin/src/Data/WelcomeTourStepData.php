<?php

declare(strict_types=1);

namespace Capell\Admin\Data;

use Closure;
use Illuminate\Support\HtmlString;
use Illuminate\View\View;
use Spatie\LaravelData\Data;

final class WelcomeTourStepData extends Data
{
    public function __construct(
        public readonly string $key,
        public readonly string|Closure $title,
        public readonly string|Closure|HtmlString|View $description,
        public readonly ?string $element = null,
        public readonly ?string $icon = null,
        public readonly ?string $iconColor = null,
        public readonly int $sort = 100,
        public readonly bool|Closure $visible = true,
    ) {}

    public function isVisible(): bool
    {
        return is_bool($this->visible) ? $this->visible : (bool) ($this->visible)();
    }
}
