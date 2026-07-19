<?php

declare(strict_types=1);

namespace Capell\Frontend\Enums;

enum FrontendComponentTarget: string
{
    case Blade = 'frontend-blade';
    case Livewire = 'frontend-livewire';
}
