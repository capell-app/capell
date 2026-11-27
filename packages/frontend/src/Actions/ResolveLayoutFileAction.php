<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Models\Layout;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * The Blade view a page renders inside: the layout's own `layout_file`, else the
 * configured default.
 *
 * @method static string run(?Layout $layout)
 */
final class ResolveLayoutFileAction
{
    use AsFake;
    use AsObject;

    public function handle(?Layout $layout): string
    {
        $view = $layout?->meta['layout_file'] ?? null;

        if (is_string($view) && $view !== '') {
            return $view;
        }

        $default = config('capell-frontend.layout_file', 'capell::app');

        return is_string($default) && $default !== '' ? $default : 'capell::app';
    }
}
