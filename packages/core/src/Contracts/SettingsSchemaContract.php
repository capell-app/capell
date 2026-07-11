<?php

declare(strict_types=1);

namespace Capell\Core\Contracts;

interface SettingsSchemaContract
{
    /** @return class-string */
    public static function schema(): string;
}
