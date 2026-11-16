<?php

declare(strict_types=1);

namespace Capell\Core\Data\Capabilities;

use InvalidArgumentException;
use Spatie\LaravelData\Data;

/**
 * A widget a package asks the layout host to place on a page. The widget renders $component with
 * $meta; an enabled widget with the same component whose meta $matchKey holds the same value is
 * reused instead of creating another. New widgets take $keyPrefix as their key, then
 * $keyPrefix-2, -3 and so on. $layoutName names the copy made when the page's layout is shared.
 */
final class LayoutWidgetPlacementRequestData extends Data
{
    /**
     * @param  array<string, scalar|null>  $meta
     */
    public function __construct(
        public string $keyPrefix,
        public string $name,
        public string $component,
        public string $matchKey,
        public array $meta,
        public string $layoutName,
        public ?string $configurator = null,
    ) {
        throw_if(
            trim($keyPrefix) === '' || trim($component) === '' || trim($matchKey) === '',
            InvalidArgumentException::class,
            'A widget placement needs a key prefix, a component and a match key.',
        );
        throw_unless(
            is_scalar($meta[$matchKey] ?? null) && trim((string) $meta[$matchKey]) !== '',
            InvalidArgumentException::class,
            'A widget placement needs a value for its match key in its meta.',
        );
    }

    public function matchValue(): string
    {
        return (string) $this->meta[$this->matchKey];
    }
}
