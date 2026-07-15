<?php

declare(strict_types=1);

namespace Capell\Frontend\Enums;

enum ScriptExecutionMode: string
{
    case Module = 'module';
    case Classic = 'classic';
}
