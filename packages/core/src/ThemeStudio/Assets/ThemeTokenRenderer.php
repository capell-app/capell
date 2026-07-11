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

        foreach ((new ThemeTokenValidator)->sanitize($brand->tokens(), $fallbackTokens) as $token => $value) {
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
