<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands\Concerns;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * @mixin Command
 */
trait HasFrontendAssetsOption
{
    use PromptsWithOptionFallback;

    /**
     * @return list<string>|null
     */
    private function getFrontendAssets(): ?array
    {
        /** @var mixed $assets */
        $assets = $this->option('assets');

        if ($assets !== null && $assets !== []) {
            if (is_string($assets)) {
                if ($assets === 'default') {
                    return $this->defaultFrontendAssets();
                }

                $assetOptions = collect(explode(',', $assets));
            } elseif (is_array($assets)) {
                $assetOptions = collect($assets);
            } elseif ($assets instanceof Collection) {
                $assetOptions = $assets->keys();
            } else {
                throw new InvalidArgumentException('The --assets option must be a string, array or collection.');
            }

            $assetOptions = array_values($assetOptions
                ->map(fn (mixed $asset): string => trim((string) $asset))
                ->filter(fn (string $asset): bool => $asset !== '')
                ->values()
                ->all());

            throw_if($assetOptions === [], InvalidArgumentException::class, 'The --assets option must contain at least one asset path. Use --assets=default for resources/css/app.css.');

            return $assetOptions;
        }

        if (! $this->input->isInteractive() && $this->defaultFrontendAssetsExist()) {
            return $this->defaultFrontendAssets();
        }

        $this->requireInteractiveOrFail(
            'Frontend asset paths',
            'Pass --assets=<path-to-app.css>[,<path-to-app.js>] or create resources/css/app.css.',
        );

        $defaultAssets = $this->defaultFrontendAssets();
        $defaultCss = $defaultAssets[0];
        $defaultJs = 'resources/js/app.js';

        if ($this->defaultFrontendAssetsExist()) {
            $assetMode = select(
                label: sprintf(
                    "I've detected your frontend resources:\n%s\n\nIs this correct?",
                    implode("\n", $defaultAssets),
                ),
                options: [
                    'use' => 'Yes, use these',
                    'skip' => 'Ignore frontend assets',
                    'edit' => 'Change paths',
                ],
                default: 'use',
            );

            if ($assetMode === 'use') {
                return $defaultAssets;
            }

            if ($assetMode === 'skip') {
                return null;
            }
        }

        $relativeCss = text(
            label: 'App CSS path',
            default: File::exists(base_path($defaultCss)) ? $defaultCss : '',
            required: true,
            hint: 'Enter the path to your app CSS file.',
        );

        $relativeJs = text(
            label: 'App JS path',
            default: File::exists(base_path($defaultJs)) ? $defaultJs : '',
            required: true,
            hint: 'Enter the path to your app JS file.',
        );

        return [
            $relativeCss,
            $relativeJs,
        ];
    }

    /** @return list<string> */
    private function defaultFrontendAssets(): array
    {
        return [
            'resources/css/app.css',
        ];
    }

    private function defaultFrontendAssetsExist(): bool
    {
        foreach ($this->defaultFrontendAssets() as $asset) {
            if (! File::exists(base_path($asset))) {
                return false;
            }
        }

        return true;
    }
}
