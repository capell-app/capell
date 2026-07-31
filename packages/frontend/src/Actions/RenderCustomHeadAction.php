<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Illuminate\Support\Facades\Blade;
use Livewire\Blaze\BlazeManager;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Render theme-owned head extensions outside Blaze's function compiler.
 *
 * Extension views can contain PHP that is valid at file scope but invalid
 * after Blaze wraps it in a function. Ordinary Blade remains the compatibility
 * boundary for this customizable fragment.
 *
 * @method static string run(string $title, ?string $description, ?string $keywords)
 */
final class RenderCustomHeadAction
{
    use AsFake;
    use AsObject;

    public function handle(string $title, ?string $description, ?string $keywords): string
    {
        $blaze = resolve(BlazeManager::class);
        $restoreBlaze = $blaze->isEnabled();

        if ($restoreBlaze) {
            $blaze->disable();
        }

        try {
            return Blade::render(
                '@include($view, $data)',
                [
                    'view' => 'capell::components.app.head.custom',
                    'data' => [
                        'title' => $title,
                        'description' => $description,
                        'keywords' => $keywords,
                    ],
                ],
            );
        } finally {
            if ($restoreBlaze) {
                $blaze->enable();
            }
        }
    }
}
