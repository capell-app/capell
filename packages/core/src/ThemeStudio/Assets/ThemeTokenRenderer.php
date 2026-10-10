<?php

declare(strict_types=1);

namespace Capell\Core\ThemeStudio\Assets;

use Capell\Core\ThemeStudio\Data\BrandProfileData;

class ThemeTokenRenderer
{
    public function css(BrandProfileData $brand): string
    {
        $lines = [];
        $fallbackTokens = (new BrandProfileData)->tokens();

        $validator = new ThemeTokenValidator;
        $tokens = $validator->sanitize($brand->tokens(), $fallbackTokens);

        // Pair labels with the colour actually emitted after safe fallbacks.
        foreach (['primary', 'accent'] as $name) {
            $tokens['--theme-' . $name . '-contrast'] = $validator->contrastIssues('#000000', $tokens['--theme-' . $name]) === []
                ? '#000000'
                : '#ffffff';
        }

        foreach ($tokens as $token => $value) {
            $lines[] = '    ' . $token . ': ' . $value . ';';
        }

        return ":root {\n" . implode("\n", $lines) . "\n}\n";
    }

    /**
     * @return array<int, string>
     */
    public function contrastIssues(BrandProfileData $brand): array
    {
        $validator = new ThemeTokenValidator;

        return [
            ...$validator->contrastIssues($brand->foregroundColor, $brand->surfaceColor, 'foreground/surface'),
            ...$validator->contrastIssues($brand->primaryColor, $brand->surfaceColor, 'primary/surface'),
            ...$validator->contrastIssues($brand->accentColor, $brand->surfaceColor, 'accent/surface'),
            ...$validator->contrastIssues($brand->neutralColor, $brand->surfaceColor, 'neutral/surface'),
        ];
    }
}
