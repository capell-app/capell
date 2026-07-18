<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Widgets;

use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class PruneBlankWidgetSettingsAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  array<int|string, mixed>  $settings
     * @return array<int|string, mixed>
     */
    public function handle(array $settings): array
    {
        foreach ($settings as $key => $value) {
            if (is_array($value)) {
                $value = $this->handle($value);
            }

            if (blank($value)) {
                unset($settings[$key]);

                continue;
            }

            $settings[$key] = $value;
        }

        return $settings;
    }
}
