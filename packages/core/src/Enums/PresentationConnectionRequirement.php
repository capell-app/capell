<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

enum PresentationConnectionRequirement: string
{
    case Any = 'any';
    case FastOnly = 'fast_only';
    case HideOnSaveData = 'hide_on_save_data';
}
