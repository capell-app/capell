<?php

declare(strict_types=1);

namespace Capell\Core\Contracts;

interface SettingsOwnerContract
{
    /**
     * Returns the fully-qualified class name of this package's settings class.
     * The admin service provider should implement this and pass the result to
     * the SettingsSchemaRegistry for auto-registration.
     */
    public static function getSettingsClass(): string;
}
