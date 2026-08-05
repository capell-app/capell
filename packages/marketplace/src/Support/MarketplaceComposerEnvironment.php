<?php

declare(strict_types=1);

namespace Capell\Marketplace\Support;

/**
 * The environment every Marketplace Composer subprocess runs under.
 *
 * Both the `require` run and the replay of the application's own
 * post-autoload-dump scripts have to see the same cache directory, the same
 * memory limit, and the same proxy settings — otherwise the second run
 * re-downloads what the first one cached, or cannot reach the network at all.
 */
final class MarketplaceComposerEnvironment
{
    public function cacheDirectory(): string
    {
        $configured = config('capell.process.composer.cache_dir');

        return is_string($configured) && $configured !== ''
            ? $configured
            : storage_path('framework/composer/cache');
    }

    public function memoryLimit(): string
    {
        $configured = config('capell.process.composer.memory_limit', '-1');

        return is_scalar($configured) && (string) $configured !== '' ? (string) $configured : '-1';
    }

    /**
     * @return array<string, string|false>
     */
    public function variables(string $composerHome): array
    {
        $home = getenv('HOME');

        return [
            ...$this->proxyVariables(),
            'COMPOSER_CACHE_DIR' => $this->cacheDirectory(),
            'COMPOSER_HOME' => $composerHome,
            'COMPOSER_MEMORY_LIMIT' => $this->memoryLimit(),
            'COMPOSER_AUTH' => false,
            'COMPOSER_TOKEN' => false,
            'GIT_ASKPASS' => false,
            'GIT_TERMINAL_PROMPT' => '0',
            'GITHUB_TOKEN' => false,
            'GITHUB_AUTH_TOKEN' => false,
            'GITLAB_TOKEN' => false,
            'HOME' => is_string($home) && $home !== '' ? $home : $composerHome,
            'PACKAGIST_TOKEN' => false,
            'SSH_AUTH_SOCK' => false,
        ];
    }

    /**
     * Corporate networks and some shared hosts have no outbound access at all
     * without these, so Composer has to be told about them explicitly rather
     * than left to whatever the queue worker happened to inherit.
     *
     * @return array<string, string>
     */
    private function proxyVariables(): array
    {
        $proxyEnvironment = [];

        foreach (['HTTP_PROXY', 'HTTPS_PROXY', 'NO_PROXY'] as $key) {
            // curl reads the lower-case spelling, Composer the upper-case one,
            // and hosts set either.
            foreach ([$key, strtolower($key)] as $variant) {
                $value = getenv($variant);

                if (is_string($value) && $value !== '') {
                    $proxyEnvironment[$variant] = $value;
                }
            }
        }

        return $proxyEnvironment;
    }
}
