<?php

declare(strict_types=1);

namespace Capell\Core\Data\Capabilities;

use InvalidArgumentException;
use Spatie\LaravelData\Data;

/**
 * Widgets rendered by one component whose meta key holds one of the given values.
 */
final class LayoutWidgetMatchData extends Data
{
    /**
     * @param  list<string>  $values
     */
    public function __construct(
        public string $component,
        public string $metaKey,
        public array $values,
    ) {
        throw_if(trim($component) === '' || trim($metaKey) === '', InvalidArgumentException::class, 'A widget match needs a component and a meta key.');
    }
}
