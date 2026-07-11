<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

enum ContentStructure: string
{
    case Blocks = 'blocks';
    case Html = 'html';

    public function isArray(): bool
    {
        return $this === self::Blocks;
    }
}
