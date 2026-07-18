<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

enum AssetComponentEnum: string
{
    case Card = 'capell.asset.card';

    case Media = 'capell.asset.media';

    case Page = 'capell.asset.page';

    case Tile = 'capell.asset.tile';

    public function bladeView(): string
    {
        return match ($this) {
            self::Card => 'capell::asset.index',
            self::Media => 'capell::media.asset',
            self::Page => 'capell::page.asset',
            self::Tile => 'capell::asset.tile',
        };
    }
}
