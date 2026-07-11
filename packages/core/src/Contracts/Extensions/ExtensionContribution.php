<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Extensions;

interface ExtensionContribution
{
    public static function compatibleCapellApiVersion(): string;
}
