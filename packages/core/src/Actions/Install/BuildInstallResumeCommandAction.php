<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Data\InstallInputData;
use Capell\Core\Support\Install\InstallSiteUrl;
use Illuminate\Support\Facades\Request;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildInstallResumeCommandAction
{
    use AsFake;
    use AsObject;

    /**
     * Only these options are replayed. Anything else, including options added later and every
     * credential, licence key, token, API key or database setting, is dropped until it is reviewed
     * and added here. Destructive (--fresh) and preview-only (--plan) options are deliberately absent.
     * --publish is replayed because it is idempotent: it only publishes pages that are still draft.
     */
    private const array VALUE_OPTIONS = [
        '--site-name', '--url', '--preset', '--recommendation', '--recommendation-action', '--package-mode', '--packages',
        '--table-prefix', '--table-alias', '--theme', '--languages', '--sites', '--spec', '--user', '--name',
        '--email', '--profile', '--save-profile',
    ];

    private const array FLAG_OPTIONS = [
        '--no-interaction', '-n', '--customise', '--build-assets', '--update-app-url', '--demo', '--minimal',
        '--no-frontend-build', '--publish', '--allow-demo-credentials', '--all-packages', '--remove-installer', '--seed',
        '--no-seed-default-data', '--role-users', '--clear-cache', '--generate-sitemap', '--install-welcome-route',
        '--developer-tooling', '--no-boost-install', '--production',
    ];

    /**
     * The command that reruns the installer the way the stopped run was started, so every choice
     * (theme, site name, seed flags, table names) carries over. A run that did not start from the
     * console falls back to the packages and address recorded in its input.
     *
     * @param  list<string>|null  $argv
     */
    public function handle(InstallInputData $input, ?array $argv = null): string
    {
        $consoleArguments = Request::server('argv');
        $argv ??= is_array($consoleArguments) ? array_values(array_map(strval(...), $consoleArguments)) : [];
        $position = array_search('capell:install', $argv, true);

        if ($position !== false) {
            $options = [];
            $arguments = array_slice($argv, $position + 1);

            foreach ($arguments as $index => $argument) {
                $name = strtok($argument, '=');

                if (in_array($name, self::FLAG_OPTIONS, true) && ! str_contains($argument, '=')) {
                    $options[] = $argument;
                } elseif (in_array($name, self::VALUE_OPTIONS, true)) {
                    $value = str_contains($argument, '=') ? substr($argument, strlen($name) + 1) : ($arguments[$index + 1] ?? null);

                    if (is_string($value) && ! $this->carriesCredentials($name, $value)) {
                        $options[] = $name . '=' . $value;
                    }
                }
            }

            return trim('php artisan capell:install ' . implode(' ', array_map(escapeshellarg(...), $options)));
        }

        $urlOption = InstallSiteUrl::validationError($input->siteUrl) === null
            ? ' --url=' . escapeshellarg(InstallSiteUrl::publicUrl($input->siteUrl))
            : '';

        return 'php artisan capell:install --no-interaction' . $urlOption
            . ' --packages=' . escapeshellarg(implode(',', array_unique([...$input->packages, ...$input->extraPackages])));
    }

    private function carriesCredentials(string $option, string $value): bool
    {
        if ($option !== '--url') {
            return false;
        }

        $parts = parse_url($value);

        return is_array($parts) && (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']));
    }
}
