<?php

declare(strict_types=1);

namespace Capell\Core\Enums\ContentGraph;

enum ContentGraphEdgeStrength: string
{
    case Strong = 'strong';
    case Weak = 'weak';
    case Informational = 'informational';
}
