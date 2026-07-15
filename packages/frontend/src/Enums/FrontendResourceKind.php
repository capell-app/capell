<?php

declare(strict_types=1);

namespace Capell\Frontend\Enums;

enum FrontendResourceKind: string
{
    case Style = 'style';
    case ModuleScript = 'module-script';
    case ClassicScript = 'classic-script';
    case InlineStyle = 'inline-style';
    case InlineScript = 'inline-script';

    public function isScript(): bool
    {
        return match ($this) {
            self::ModuleScript, self::ClassicScript, self::InlineScript => true,
            self::Style, self::InlineStyle => false,
        };
    }

    public function isInline(): bool
    {
        return match ($this) {
            self::InlineStyle, self::InlineScript => true,
            default => false,
        };
    }
}
