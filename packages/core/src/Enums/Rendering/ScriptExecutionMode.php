<?php

declare(strict_types=1);

namespace Capell\Core\Enums\Rendering;

enum ScriptExecutionMode: string
{
    case Module = 'module';
    case Classic = 'classic';
}
