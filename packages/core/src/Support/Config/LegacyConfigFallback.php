<?php

declare(strict_types=1);

namespace Capell\Core\Support\Config;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Arr;

/**
 * Carries values an application set under a config key Capell no longer owns
 * (`backup`, `redirects`) across to the prefixed key that replaced it, so an
 * existing install keeps its settings while it moves to the new file name.
 */
final class LegacyConfigFallback
{
    /** The unprefixed keys Capell used before its config files were prefixed, and the keys that replaced them. */
    private const array RENAMED_KEYS = [
        'backup' => 'capell-backup',
        'redirects' => 'capell-redirects',
    ];

    /**
     * Carries every renamed Capell config key an application still sets under its old name,
     * raising one deprecation per old key that was found. Returns the old keys that were carried.
     *
     * @return list<string>
     */
    public static function carryRenamedKeys(Repository $config): array
    {
        $found = [];

        foreach (self::RENAMED_KEYS as $legacyKey => $newKey) {
            if (self::apply($config, $legacyKey, $newKey, dirname(__DIR__, 3) . '/config/' . $newKey . '.php') !== []) {
                self::deprecate($legacyKey, $newKey);
                $found[] = $legacyKey;
            }
        }

        return $found;
    }

    /**
     * Copies the legacy values whose names Capell's own defaults define into the
     * new key, without overriding anything the application already set there.
     * Returns the names of the legacy keys that were carried over.
     *
     * @return list<string>
     */
    public static function apply(Repository $config, string $legacyKey, string $newKey, string $defaultsPath): array
    {
        $legacy = $config->get($legacyKey);

        if (! is_array($legacy) || ! is_file($defaultsPath)) {
            return [];
        }

        $defaults = require $defaultsPath;

        if (! is_array($defaults)) {
            return [];
        }

        $carried = Arr::only($legacy, array_keys($defaults));

        if ($carried === []) {
            return [];
        }

        $current = $config->get($newKey);
        $config->set($newKey, [...$carried, ...(is_array($current) ? $current : [])]);

        return array_keys($carried);
    }

    /** Raises a deprecation naming the old key and the file that replaces it. */
    public static function deprecate(string $legacyKey, string $newKey): void
    {
        trigger_error(
            sprintf(
                'The [%1$s] config key is deprecated for Capell settings and will stop being read in the next minor version. Move those values to config/%2$s.php under the [%2$s] key.',
                $legacyKey,
                $newKey,
            ),
            E_USER_DEPRECATED,
        );
    }
}
