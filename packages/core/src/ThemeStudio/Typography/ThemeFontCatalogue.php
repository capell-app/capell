<?php

declare(strict_types=1);

namespace Capell\Core\ThemeStudio\Typography;

/**
 * The single source of truth for the font keys a theme setting may name.
 *
 * Each key maps to its CSS family name, its fallback stack and, where the
 * family ships as a self-hosted WOFF2 file, that file's name in the Foundation
 * font bundle. Stacks are fixed literals, so a setting value never reaches CSS
 * unless it is one of these keys.
 */
final class ThemeFontCatalogue
{
    public const string DEFAULT_KEY = 'inter';

    /** Public path the Foundation theme publishes its font bundle to. */
    public const string BUNDLE_PATH = '/vendor/capell-theme-foundation/fonts/';

    private const string SANS = "'Inter', system-ui, sans-serif";

    /**
     * @return array<string, array{family: string, stack: string, file: string|null}>
     */
    public static function all(): array
    {
        return [
            'inter' => self::entry('Inter', self::SANS, 'Inter-Variable.woff2'),
            'sora' => self::entry('Sora', "'Sora', 'Inter', system-ui, sans-serif", 'Sora-Variable.woff2'),
            'manrope' => self::entry('Manrope', "'Manrope', 'Inter', system-ui, sans-serif", 'Manrope-Variable.woff2'),
            'archivo' => self::entry('Archivo', "'Archivo', 'Inter', system-ui, sans-serif", 'Archivo-Variable.woff2'),
            'space-grotesk' => self::entry('Space Grotesk', "'Space Grotesk', 'Inter', system-ui, sans-serif", 'SpaceGrotesk-Variable.woff2'),
            'ibm-plex-sans' => self::entry('IBM Plex Sans', "'IBM Plex Sans', 'Inter', system-ui, sans-serif", 'IBMPlexSans-Variable.woff2'),
            'source-sans' => self::entry('Source Sans 3', "'Source Sans 3', 'Inter', system-ui, sans-serif", 'SourceSans3-Variable.woff2'),
            'playfair' => self::entry('Playfair Display', "'Playfair Display', Georgia, serif", 'PlayfairDisplay-Variable.woff2'),
            'fraunces' => self::entry('Fraunces', "'Fraunces', Georgia, serif", 'Fraunces-Variable.woff2'),
            'newsreader' => self::entry('Newsreader', "'Newsreader', Georgia, serif", 'Newsreader-Variable.woff2'),
            'source-serif' => self::entry('Source Serif 4', "'Source Serif 4', Georgia, serif", 'SourceSerif4-Variable.woff2'),
            'ibm-plex-mono' => self::entry('IBM Plex Mono', "'IBM Plex Mono', ui-monospace, monospace", 'IBMPlexMono-Regular.woff2'),
            'jetbrains-mono' => self::entry('JetBrains Mono', "'JetBrains Mono', ui-monospace, monospace", 'JetBrainsMono-Variable.woff2'),
            // Self-hosted by the Almanac theme's own stylesheet, not the Foundation bundle.
            'bricolage-grotesque' => self::entry('Bricolage Grotesque Almanac', "'Bricolage Grotesque Almanac', 'Inter', system-ui, sans-serif", null),
            // No font file: the visitor's system interface font.
            'system' => self::entry('system-ui', 'system-ui, -apple-system, sans-serif', null),
        ];
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    public static function stack(string $key): string
    {
        return (self::all()[$key] ?? self::all()[self::DEFAULT_KEY])['stack'];
    }

    /**
     * The Foundation bundle file for a key, or null when the key has none.
     */
    public static function file(string $key): ?string
    {
        return (self::all()[$key] ?? self::all()[self::DEFAULT_KEY])['file'];
    }

    /**
     * The public URL path of the key's bundled font file, or null when it has none.
     */
    public static function preloadPath(string $key): ?string
    {
        $file = self::all()[$key]['file'] ?? null;

        return $file === null ? null : self::BUNDLE_PATH . $file;
    }

    /**
     * @return array{family: string, stack: string, file: string|null}
     */
    private static function entry(string $family, string $stack, ?string $file): array
    {
        return ['family' => $family, 'stack' => $stack, 'file' => $file];
    }
}
