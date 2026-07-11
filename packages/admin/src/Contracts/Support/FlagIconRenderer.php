<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Support;

use Illuminate\Support\HtmlString;

interface FlagIconRenderer
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function render(?string $flag, ?string $label = null, string $style = '4x3', array $attributes = []): HtmlString;
}
