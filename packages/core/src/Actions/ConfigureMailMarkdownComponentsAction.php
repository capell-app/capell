<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class ConfigureMailMarkdownComponentsAction
{
    use AsFake;
    use AsObject;

    public function handle(): void
    {
        $packagePath = dirname(__DIR__, 2) . '/resources/views/mail';

        $paths = config('mail.markdown.paths');
        $paths = is_array($paths) ? $paths : [];

        if (in_array($packagePath, $paths, true)) {
            return;
        }

        // Prepend so the package's logo-aware mail header takes precedence.
        // Laravel always seeds mail.markdown.paths with resource_path(
        // 'views/vendor/mail'), so bailing when the array is non-empty meant
        // the package header was never registered and the logo never showed.
        config(['mail.markdown.paths' => array_merge([$packagePath], $paths)]);
    }
}
