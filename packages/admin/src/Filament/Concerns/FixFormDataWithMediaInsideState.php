<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Concerns;

trait FixFormDataWithMediaInsideState
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fixFormDataWithMediaInsideState(array $data): array
    {
        // Ensure only a single integer is saved for file uploading inside state path
        foreach (['image_id', 'logo_id', 'logo_inverted_id'] as $var) {
            if (! isset($data['meta'][$var])) {
                continue;
            }

            if (in_array($data['meta'][$var], ['', [], false], true)) {
                unset($data['meta'][$var]);
            } elseif (is_array($data['meta'][$var])) {
                $data['meta'][$var] = collect($data['meta'][$var])->first()['id'];
            }
        }

        return $data;
    }
}
