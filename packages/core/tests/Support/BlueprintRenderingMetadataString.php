<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

enum BlueprintRenderingMetadataString: string
{
    case Component = '  rendered.component  ';
    case Blank = '   ';
}
