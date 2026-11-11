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

    /** Options that must never be replayed: they destroy data, only preview, or carry a secret. */
    private const array UNSAFE_OPTIONS = ['--fresh', '--plan', '--password', '--role-user-password'];

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
            $skipValue = false;

            foreach (array_slice($argv, $position + 1) as $argument) {
                if ($skipValue) {
                    $skipValue = false;

                    if (! str_starts_with($argument, '-')) {
                        continue;
                    }
                }

                if (in_array(strtok($argument, '='), self::UNSAFE_OPTIONS, true)) {
                    $skipValue = ! str_contains($argument, '=');

                    continue;
                }

                $options[] = $argument;
            }

            return trim('php artisan capell:install ' . implode(' ', array_map(escapeshellarg(...), $options)));
        }

        $urlOption = InstallSiteUrl::validationError($input->siteUrl) === null
            ? ' --url=' . escapeshellarg(InstallSiteUrl::publicUrl($input->siteUrl))
            : '';

        return 'php artisan capell:install --no-interaction' . $urlOption
            . ' --packages=' . escapeshellarg(implode(',', array_unique([...$input->packages, ...$input->extraPackages])));
    }
}
