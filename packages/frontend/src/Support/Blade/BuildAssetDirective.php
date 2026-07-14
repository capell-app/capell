<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Blade;

final class BuildAssetDirective
{
    public function compile(string $expression): string
    {
        return "<?php
                \$args = [{$expression}];
                \$buildAssets = \\Illuminate\\Support\\Arr::wrap(\$args[0] ?? []);
                \$buildPath = \$args[1] ?? null;
                \$buildTool = \$args[2] ?? 'vite';
                if (\$buildAssets !== []) {
                    if (\$buildTool === 'vite') {
                        echo app(\\Illuminate\\Foundation\\Vite::class)(\$buildAssets, \$buildPath);
                    } else {
                        foreach (\$buildAssets as \$buildAsset) {
                            if (\\Illuminate\\Support\\Str::endsWith(\$buildAsset, '.css')) {
                                echo '<link rel=\"stylesheet\" href=\"' . app('url')->asset(\$buildPath . '/' . \$buildAsset) . '\">';
                            } elseif (\\Illuminate\\Support\\Str::endsWith(\$buildAsset, '.js')) {
                                echo '<script src=\"' . app('url')->asset(\$buildPath . '/' . \$buildAsset) . '\"></script>';
                            }
                        }
                    }
                }
            ?>";
    }
}
