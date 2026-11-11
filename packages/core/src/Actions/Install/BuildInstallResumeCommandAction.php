<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Data\InstallInputData;
use Capell\Core\Support\Install\InstallSiteUrl;
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
        '--no-interaction', '--customise', '--build-assets', '--update-app-url', '--demo', '--minimal',
        '--no-frontend-build', '--publish', '--allow-demo-credentials', '--all-packages', '--remove-installer', '--seed',
        '--no-seed-default-data', '--role-users', '--clear-cache', '--generate-sitemap', '--install-welcome-route',
        '--developer-tooling', '--no-boost-install', '--production',
    ];

    /**
     * Replay only reviewed options from the install command's parsed input. Non-console runs
     * fall back to the packages and address recorded in their input.
     *
     * @param  array<string, mixed>|null  $options
     */
    public function handle(InstallInputData $input, ?array $options = null): string
    {
        if ($options !== null) {
            $arguments = [];
            foreach (self::FLAG_OPTIONS as $option) {
                if (($options[ltrim($option, '-')] ?? false) === true) {
                    $arguments[] = $option;
                }
            }

            foreach (self::VALUE_OPTIONS as $option) {
                $value = $options[ltrim($option, '-')] ?? null;
                foreach (is_array($value) ? $value : [$value] as $entry) {
                    if (is_string($entry) && ! $this->carriesCredentials($option, $entry)) {
                        $arguments[] = $option . '=' . $entry;
                    }
                }
            }

            return trim('php artisan capell:install ' . implode(' ', array_map(escapeshellarg(...), $arguments)));
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
