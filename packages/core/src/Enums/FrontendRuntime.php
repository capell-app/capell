<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

enum FrontendRuntime: string
{
    case Blade = 'blade';
    case Livewire = 'livewire';
    case Inertia = 'inertia';
}
