<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

enum AssetComponentEnum: string
{
    case Card = 'capell.asset.card';

    case Media = 'capell.asset.media';

    case Page = 'capell.asset.page';

    case Tile = 'capell.asset.tile';
}
