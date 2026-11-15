<?php

declare(strict_types=1);

namespace Capell\Core\ThemeStudio\Data;

use Capell\Core\ThemeStudio\Typography\ThemeFontCatalogue;
use Spatie\LaravelData\Data;

/**
 * A whole look for a theme: brand token values plus, optionally, the type
 * pairing, page layout and per-section variants the look is composed from.
 *
 * Editor customisations are stored apart from the preset as a diff (see
 * diffOverrides()), so raising $version and changing a value updates every
 * site that left it untouched while keeping the values an editor changed.
 */
class ThemePresetData extends Data
{
    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, string>  $sectionVariants  Section key => variant key.
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $description,
        public string $previewImage,
        public array $values = [],
        public int $version = 1,
        public ?string $typePairing = null,
        public ?string $layout = null,
        public array $sectionVariants = [],
    ) {}

    public function sectionVariant(string $section): ?string
    {
        return $this->sectionVariants[$section] ?? null;
    }

    /**
     * Whether this preset changes composition or type, not only colour.
     */
    public function composesLook(): bool
    {
        return $this->typePairing !== null || $this->layout !== null || $this->sectionVariants !== [];
    }

    /**
     * The brand values this preset applies: its type pairing and layout
     * expanded to the brand keys they set, with explicit values winning.
     *
     * A type pairing is `heading+body` (or one key for both) from the font
     * catalogue; an unknown font is ignored rather than guessed.
     *
     * @return array<string, mixed>
     */
    public function resolvedValues(): array
    {
        $look = [];

        if ($this->typePairing !== null) {
            $fonts = explode('+', $this->typePairing, 2);
            $heading = trim($fonts[0]);
            $body = trim($fonts[1] ?? $heading);

            if (ThemeFontCatalogue::has($heading)) {
                $look['headingFont'] = $heading;
            }

            if (ThemeFontCatalogue::has($body)) {
                $look['bodyFont'] = $body;
            }
        }

        if ($this->layout !== null && $this->layout !== '') {
            $look['layoutPresentation'] = $this->layout;
        }

        return [...$look, ...$this->values];
    }

    /**
     * Reduce an editor's submitted values to the entries that differ from this
     * preset, so only real customisations are stored.
     *
     * @param  array<string, mixed>  $submitted
     * @return array<string, mixed>
     */
    public function diffOverrides(array $submitted): array
    {
        $resolved = $this->resolvedValues();

        return array_filter(
            $submitted,
            fn (mixed $value, string $key): bool => ! array_key_exists($key, $resolved) || $resolved[$key] !== $value,
            ARRAY_FILTER_USE_BOTH,
        );
    }
}
