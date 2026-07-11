<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

enum ComponentTypeEnum: string
{
    case Asset = AssetComponentEnum::class;

    case Page = LivewirePageComponentEnum::class;
}
