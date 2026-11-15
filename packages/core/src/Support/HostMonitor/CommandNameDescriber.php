<?php

declare(strict_types=1);

namespace Capell\Core\Support\HostMonitor;

/**
 * Names what a process is running without its arguments, which can hold
 * credentials. PHP processes also name the artisan command, because
 * "php artisan" alone says nothing about which task is busy.
 */
final class CommandNameDescriber
{
    /** @param  list<string>  $arguments */
    public static function describe(array $arguments): string
    {
        if ($arguments === [] || $arguments[0] === '') {
            return '';
        }

        // A program that rewrites its process title puts everything in the first field, so keep one word.
        $name = basename(explode(' ', trim($arguments[0]))[0]);
        $artisan = array_search('artisan', array_map(basename(...), $arguments), true);

        if (is_int($artisan) && isset($arguments[$artisan + 1]) && preg_match('/\A[a-z][a-z0-9:_-]*\z/i', $arguments[$artisan + 1]) === 1) {
            return $name . ' artisan ' . $arguments[$artisan + 1];
        }

        return $name;
    }
}
