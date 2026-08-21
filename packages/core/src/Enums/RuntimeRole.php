<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

enum RuntimeRole: string
{
    case Combined = 'combined';
    case Public = 'public';
    case Authoring = 'authoring';

    /** @return list<self> */
    public static function deploymentRoles(): array
    {
        return [self::Combined, self::Public, self::Authoring];
    }

    public function loadsAuthoringProviders(): bool
    {
        return $this !== self::Public;
    }
}
