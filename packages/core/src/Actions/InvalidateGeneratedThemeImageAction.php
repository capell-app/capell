<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Models\Theme;
use Illuminate\Support\Facades\Storage;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static void run(Theme $theme, string $signature)
 */
class InvalidateGeneratedThemeImageAction
{
    use AsFake;
    use AsObject;

    public function handle(Theme $theme, string $signature): void
    {
        $admin = is_array($theme->admin) ? $theme->admin : [];
        $image = $admin['generated_image'] ?? null;

        if (is_string($image) && $image !== '') {
            Storage::disk('public')->delete($image);
        }

        unset($admin['generated_image'], $admin['generated_image_error']);

        $admin['generated_image_signature'] = $signature;
        $admin['generated_image_status'] = 'pending';

        $theme->writeGeneratedImageMetadata($admin);
    }
}
