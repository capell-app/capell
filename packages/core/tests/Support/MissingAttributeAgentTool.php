<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

use Capell\Core\Concerns\HasAgentToolDefinition;
use Capell\Core\Contracts\Agent\DefinesAgentTool;
use Override;

final class MissingAttributeAgentTool implements DefinesAgentTool
{
    use HasAgentToolDefinition;

    #[Override]
    public static function compatibleCapellApiVersion(): string
    {
        return '^1.0';
    }
}
