<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Models\Theme;
use Illuminate\Support\Facades\Storage;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static void run(Theme $theme)
 */
class ClearGeneratedThemeImageAction
{
    use AsFake;
    use AsObject;

    public function handle(Theme $theme): void
    {
        $admin = is_array($theme->admin) ? $theme->admin : [];

        if (! array_key_exists('generated_image', $admin)
            && ! array_key_exists('generated_image_signature', $admin)
            && ! array_key_exists('generated_image_status', $admin)
            && ! array_key_exists('generated_image_error', $admin)) {
            return;
        }

        $image = $admin['generated_image'] ?? null;

        if (is_string($image) && $image !== '') {
            Storage::disk('public')->delete($image);
        }

        unset(
            $admin['generated_image'],
            $admin['generated_image_signature'],
            $admin['generated_image_status'],
            $admin['generated_image_error'],
        );

        Theme::withoutEvents(function () use ($theme, $admin): void {
            $theme->forceFill(['admin' => $admin])->save();
        });
    }
}
