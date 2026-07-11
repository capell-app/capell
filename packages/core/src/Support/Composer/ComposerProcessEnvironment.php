<?php

declare(strict_types=1);

namespace Capell\Core\Support\Composer;

final class ComposerProcessEnvironment
{
    /**
     * @param  array<string, string>  $environment
     * @return array<string, string>
     */
    public static function forInstall(array $environment): array
    {
        $gitConfig = [
            ['safe.directory', '*'],
            ['url.https://github.com/.insteadOf', 'git@github.com:'],
            ['url.https://github.com/.insteadOf', 'ssh://git@github.com/'],
        ];

        $composerEnvironment = array_merge($environment, [
            'GIT_CONFIG_COUNT' => (string) count($gitConfig),
        ]);

        if (! isset($composerEnvironment['PATH']) || $composerEnvironment['PATH'] === '') {
            $path = getenv('PATH');

            $composerEnvironment['PATH'] = is_string($path) && $path !== ''
                ? $path
                : '/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin';
        }

        if (! isset($composerEnvironment['HOME']) || $composerEnvironment['HOME'] === '') {
            $home = getenv('HOME');

            if (is_string($home) && $home !== '') {
                $composerEnvironment['HOME'] = $home;
            }
        }

        if (! isset($composerEnvironment['COMPOSER_HOME']) || $composerEnvironment['COMPOSER_HOME'] === '') {
            $composerHome = getenv('COMPOSER_HOME');

            if (is_string($composerHome) && $composerHome !== '') {
                $composerEnvironment['COMPOSER_HOME'] = $composerHome;
            } elseif (isset($composerEnvironment['HOME']) && $composerEnvironment['HOME'] !== '') {
                $composerEnvironment['COMPOSER_HOME'] = rtrim($composerEnvironment['HOME'], DIRECTORY_SEPARATOR) . '/.composer';
            }
        }

        $composerFile = getenv('COMPOSER');
        if (is_string($composerFile) && $composerFile !== '') {
            $composerEnvironment['COMPOSER'] = $composerFile;
        }

        foreach ($gitConfig as $index => [$key, $value]) {
            $composerEnvironment['GIT_CONFIG_KEY_' . $index] = $key;
            $composerEnvironment['GIT_CONFIG_VALUE_' . $index] = $value;
        }

        return $composerEnvironment;
    }
}
