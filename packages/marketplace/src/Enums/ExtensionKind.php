<?php

declare(strict_types=1);

namespace Capell\Marketplace\Enums;

enum ExtensionKind: string
{
    case Theme = 'theme';
    case Widget = 'widget';
    case Integration = 'integration';
    case Field = 'field';
    case Block = 'block';
    case Tool = 'tool';

    public function getLabel(): string
    {
        return match ($this) {
            self::Theme => 'Theme',
            self::Widget => 'Widget',
            self::Integration => 'Integration',
            self::Field => 'Field',
            self::Block => 'Block',
            self::Tool => 'Tool',
        };
    }
}
