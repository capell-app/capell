<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Composer\ComposerAutoloaderReloader;
use Capell\Core\Support\Json\JsonCodec;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;
use Symfony\Component\Process\Process;

class RequirePackageAction
{
    use AsFake;
    use AsObject;

    /**
     * @var callable|null
     */
    private static $processFactory;

    /**
     * Set a custom process factory (for testing).
     */
    public static function setProcessFactory(?callable $factory): void
    {
        self::$processFactory = $factory;
    }

    /**
     * Reset the process factory to default (real Process).
     */
    public static function resetProcessFactory(): void
    {
        self::$processFactory = null;
    }

    /**
     * Install a Composer package (supports private repositories with auth tokens).
     * If a token & provider are passed they override environment based auth.
     * Wildcard version constraints (e.g. vendor/package:*) are allowed only in local/dev environments.
     *
     * @return array{package:string,status:string,message:string,output:string,auth_used:bool,cache_cleared:bool}
     */
    public function handle(string $name, ?string $token = null, ?string $provider = null, ?string $domain = null): array
    {
        // If not version and local dev require wildcard
        if (! str_contains($name, ':') && app()->isLocal()) {
            $name .= ':*';
        }

        $this->guardVersionConstraint($name);

        $env = $this->prepareComposerAuthEnv($token, $provider, $domain);

        $processFactory = self::$processFactory ?? fn (array $args, string $cwd, ?array $env): Process => new Process($args, $cwd, $env);
        $process = $processFactory(['composer', 'require', $name], base_path(), $env);
        $process->setTimeout(300);
        $process->run();

        $errorOutput = $process->getErrorOutput();
        $standardOutput = $process->getOutput();

        if (! $process->isSuccessful()) {
            $authFailure = $this->isAuthFailure($errorOutput . $standardOutput);
            $baseMessage = $authFailure
                ? sprintf("Failed to install private package '%s': authentication required or invalid credentials. Configure COMPOSER_AUTH, provide a token, or set GITHUB_TOKEN / GITLAB_TOKEN / BITBUCKET_TOKEN env vars.", $name)
                : sprintf("Failed to install package '%s': ", $name);
            throw new RuntimeException(
                $baseMessage . (
                    $errorOutput !== '' ? $errorOutput : ($standardOutput !== '' ? $standardOutput : 'Unknown error during composer require.')
                ),
            );
        }

        $noStandardOutput = ($standardOutput === '' || $standardOutput === '0');
        $noErrorOutput = ($errorOutput === '' || $errorOutput === '0');

        throw_if(
            $noStandardOutput && $noErrorOutput,
            RuntimeException::class,
            sprintf("Package '%s' installation produced no output.", $name),
        );

        ComposerAutoloaderReloader::reload();
        CapellCore::clearExtensionCache();

        return [
            'package' => $name,
            'status' => 'installed',
            'message' => sprintf("Package '%s' installed successfully.", $name),
            'output' => $standardOutput,
            'auth_used' => $env !== null,
            'cache_cleared' => true,
        ];
    }

    /**
     * Guard against wildcard constraints outside local/dev environments.
     */
    protected function guardVersionConstraint(string $packageSpecifier): void
    {
        if (! str_contains($packageSpecifier, ':')) {
            return; // No explicit version constraint; acceptable everywhere.
        }

        [$pkg, $constraint] = explode(':', $packageSpecifier, 2);
        if ($pkg === '' || $constraint === '') {
            return; // Let composer handle malformed specifiers.
        }

        if (! str_contains($constraint, '*')) {
            return; // Not a wildcard constraint.
        }

        $env = config('app.env', '');
        $devEnvs = ['local', 'development', 'dev'];

        if (in_array($env, $devEnvs, true)) {
            return; // Allow wildcard in dev/local.
        }

        throw new RuntimeException(sprintf(
            "Wildcard version constraint '%s' for package '%s' is not allowed in '%s' environment. Use an explicit version (e.g. ^1.2) or omit the constraint.",
            $constraint,
            $pkg,
            $env === '' ? 'production' : $env,
        ));
    }

    /**
     * @return array{COMPOSER_AUTH: string}|null
     */
    private function prepareComposerAuthEnv(?string $token = null, ?string $provider = null, ?string $domain = null): ?array
    {
        // Explicit token overrides environment detection.
        if ($token !== null && $provider !== null) {
            $auth = [];
            $host = ($domain !== null && $domain !== '') ? $domain : $this->defaultHostForProvider($provider);
            switch ($provider) {
                case 'github':
                    $auth['github-oauth'][$host] = $token;
                    break;
                case 'gitlab':
                    $auth['gitlab-token'][$host] = $token;
                    break;
                case 'bitbucket':
                    $auth['bitbucket-oauth'][$host] = $token;
                    break;
                case 'custom-http-basic':
                    $auth['http-basic'][$host] = ['username' => 'token', 'password' => $token];
                    break;
                default:
                    $auth['github-oauth'][$host] = $token;
            }

            return [
                'COMPOSER_AUTH' => JsonCodec::encode($auth),
            ];
        }

        $existing = getenv('COMPOSER_AUTH');
        if ($existing !== false && $existing !== '') {
            return null;
        }

        $auth = [];
        if ($envToken = getenv('GITHUB_TOKEN')) {
            $auth['github-oauth']['github.com'] = $envToken;
        }

        if ($envToken = getenv('GITLAB_TOKEN')) {
            $auth['gitlab-token']['gitlab.com'] = $envToken;
        }

        if ($envToken = getenv('BITBUCKET_TOKEN')) {
            $auth['bitbucket-oauth']['bitbucket.org'] = $envToken;
        }

        if ($auth === []) {
            return null;
        }

        return [
            'COMPOSER_AUTH' => JsonCodec::encode($auth),
        ];
    }

    private function defaultHostForProvider(string $provider): string
    {
        return match ($provider) {
            'github' => 'github.com',
            'gitlab' => 'gitlab.com',
            'bitbucket' => 'bitbucket.org',
            default => $provider,
        };
    }

    private function isAuthFailure(string $output): bool
    {
        $patterns = ['authentication', 'auth failed', '403', '401', 'invalid credentials', 'not authorized'];
        $lower = strtolower($output);

        return array_any($patterns, fn (string $pattern): bool => str_contains($lower, $pattern));
    }
}
