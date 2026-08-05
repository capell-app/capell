<?php

declare(strict_types=1);

namespace Capell\Core\Support\Process;

use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;

/**
 * The one answer to "which php, and which composer, does this host use?".
 *
 * Every call site used to guess for itself, which is how an operator who had
 * correctly configured their paths could still watch an install fail: the
 * installer honoured the configuration and the Marketplace runner did not.
 *
 * Answers are argv arrays rather than strings on purpose. A resolved Composer is
 * a binary on PATH, a composer.phar that only runs as [php, composer.phar], or a
 * shell/.bat wrapper that must be executed as-is. Handing back a string forces
 * every caller to re-derive which of those three it got.
 */
final class RuntimeBinaryResolver
{
    public const string PHP_CONFIG_KEY = 'capell.process.php_binary';

    public const string COMPOSER_CONFIG_KEY = 'capell.process.composer_binary';

    /**
     * The keys the installer has always used. They remain a supported fallback
     * so an install already relying on them keeps working untouched.
     */
    public const string LEGACY_PHP_CONFIG_KEY = 'capell-installer.php_binary';

    public const string LEGACY_COMPOSER_CONFIG_KEY = 'capell-installer.composer_binary';

    public const string PHP_ENVIRONMENT_KEY = 'CAPELL_PHP_BINARY';

    public const string COMPOSER_ENVIRONMENT_KEY = 'CAPELL_COMPOSER_BINARY';

    /** Why a configured binary is unusable, for callers that report it. */
    public const string REASON_UNRESOLVABLE = 'unresolvable';

    public const string REASON_NOT_CLI = 'not_cli';

    public function __construct(
        private readonly ExecutableFinder $executableFinder = new ExecutableFinder,
    ) {}

    /**
     * @return list<string>
     */
    public function php(): array
    {
        $resolved = $this->phpOrNull();

        throw_if($resolved === null, RuntimeException::class, sprintf(
            'Unable to locate a CLI PHP binary. Set %s (or the %s environment variable) to the php executable, not php-fpm.',
            self::PHP_CONFIG_KEY,
            self::PHP_ENVIRONMENT_KEY,
        ));

        return $resolved;
    }

    /**
     * @return list<string>|null
     */
    public function phpOrNull(): ?array
    {
        foreach ($this->phpCandidates() as $candidate) {
            $binary = $this->resolveExecutable($candidate);

            if ($binary !== null && ! $this->looksLikePhpFpm($binary)) {
                return [$binary];
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function composer(): array
    {
        $resolved = $this->composerOrNull();

        throw_if($resolved === null, RuntimeException::class, sprintf(
            'Unable to locate a Composer binary. Set %s (or the %s environment variable) to composer, a composer.phar, or a Composer wrapper script.',
            self::COMPOSER_CONFIG_KEY,
            self::COMPOSER_ENVIRONMENT_KEY,
        ));

        return $resolved;
    }

    /**
     * @return list<string>|null
     */
    public function composerOrNull(): ?array
    {
        foreach ($this->composerCandidates() as $candidate) {
            $binary = $this->resolveComposerCandidate($candidate);

            if ($binary !== null) {
                return $binary;
            }
        }

        return null;
    }

    /**
     * The first explicitly configured PHP value this host cannot actually use.
     *
     * Resolution still falls back past it, because an install that can complete
     * should complete. The installer surfaces this separately so an operator
     * learns their configuration is wrong rather than silently running under a
     * different binary than the one they named.
     *
     * @return array{binary: string, reason: string}|null
     */
    public function misconfiguredPhpBinary(): ?array
    {
        // Only the highest-priority configured value can be the one in use, so
        // it is the only one worth complaining about.
        $candidate = $this->configuredCandidates(self::PHP_CONFIG_KEY, self::PHP_ENVIRONMENT_KEY, self::LEGACY_PHP_CONFIG_KEY)[0] ?? null;

        if ($candidate === null) {
            return null;
        }

        $binary = $this->resolveExecutable($candidate);

        if ($binary === null) {
            return ['binary' => $candidate, 'reason' => self::REASON_UNRESOLVABLE];
        }

        return $this->looksLikePhpFpm($binary)
            ? ['binary' => $candidate, 'reason' => self::REASON_NOT_CLI]
            : null;
    }

    /**
     * @return array{binary: string, reason: string}|null
     */
    public function misconfiguredComposerBinary(): ?array
    {
        $candidate = $this->configuredCandidates(self::COMPOSER_CONFIG_KEY, self::COMPOSER_ENVIRONMENT_KEY, self::LEGACY_COMPOSER_CONFIG_KEY)[0] ?? null;

        if ($candidate === null) {
            return null;
        }

        return $this->resolveComposerCandidate($candidate) === null
            ? ['binary' => $candidate, 'reason' => self::REASON_UNRESOLVABLE]
            : null;
    }

    /**
     * @return list<string>
     */
    private function configuredCandidates(string $configKey, string $environmentKey, string $legacyConfigKey): array
    {
        return $this->uniqueCandidates([
            $this->configured($configKey),
            $this->fromEnvironment($environmentKey),
            $this->configured($legacyConfigKey),
        ]);
    }

    /**
     * @return list<string>
     */
    private function phpCandidates(): array
    {
        return $this->uniqueCandidates([
            $this->configured(self::PHP_CONFIG_KEY),
            $this->fromEnvironment(self::PHP_ENVIRONMENT_KEY),
            $this->configured(self::LEGACY_PHP_CONFIG_KEY),
            PHP_BINARY,
            'php',
        ]);
    }

    /**
     * @return list<string>
     */
    private function composerCandidates(): array
    {
        return $this->uniqueCandidates([
            $this->configured(self::COMPOSER_CONFIG_KEY),
            $this->fromEnvironment(self::COMPOSER_ENVIRONMENT_KEY),
            $this->configured(self::LEGACY_COMPOSER_CONFIG_KEY),
            'composer',
        ]);
    }

    /**
     * @param  list<string|null>  $candidates
     * @return list<string>
     */
    private function uniqueCandidates(array $candidates): array
    {
        return array_values(array_unique(array_filter(
            $candidates,
            static fn (?string $candidate): bool => is_string($candidate) && $candidate !== '',
        )));
    }

    private function configured(string $key): ?string
    {
        $value = config($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function fromEnvironment(string $key): ?string
    {
        $value = getenv($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * A phar is data, not an executable, so it is invoked through PHP. Anything
     * else — a real binary, a shell wrapper, a Windows .bat — already knows how
     * to start itself and must be run directly.
     *
     * @return list<string>|null
     */
    private function resolveComposerCandidate(string $candidate): ?array
    {
        if ($this->looksLikePhar($candidate)) {
            $phar = $this->resolvePhar($candidate);
            $php = $phar === null ? null : $this->phpOrNull();

            return $phar !== null && $php !== null ? [...$php, $phar] : null;
        }

        $binary = $this->resolveExecutable($candidate);

        return $binary === null ? null : [$binary];
    }

    private function resolveExecutable(string $candidate): ?string
    {
        if (! str_contains($candidate, DIRECTORY_SEPARATOR)) {
            return $this->executableFinder->find($candidate);
        }

        return is_file($candidate) && is_executable($candidate) ? $candidate : null;
    }

    private function resolvePhar(string $candidate): ?string
    {
        if (! str_contains($candidate, DIRECTORY_SEPARATOR)) {
            $found = $this->executableFinder->find($candidate);

            return is_string($found) ? $found : null;
        }

        // Executability is irrelevant for a phar: PHP reads it as a file.
        return is_file($candidate) ? $candidate : null;
    }

    private function looksLikePhar(string $candidate): bool
    {
        return str_ends_with(strtolower($candidate), '.phar');
    }

    private function looksLikePhpFpm(string $binary): bool
    {
        $filename = strtolower(basename($binary));

        // PHP_BINARY under a web SAPI commonly points at php-fpm, and under CGI
        // at php-cgi. Neither runs a script the way the CLI does, so both are
        // skipped in favour of the next candidate.
        return str_contains($filename, 'php-fpm')
            || str_contains($filename, 'phpfpm')
            || str_contains($filename, 'php-cgi');
    }
}
