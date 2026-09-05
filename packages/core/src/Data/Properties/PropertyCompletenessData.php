<?php

declare(strict_types=1);

namespace Capell\Core\Data\Properties;

use Spatie\LaravelData\Data;

/**
 * The result of evaluating a page's property values against its effective
 * definitions' `required` levels.
 */
final class PropertyCompletenessData extends Data
{
    /**
     * @param  list<string>  $missingPublishRequired  Qualified keys (e.g. `commerce.product.price`) missing a value at `required: publish`.
     * @param  list<string>  $missingContractRequired  Qualified keys missing a value at `required: contract`.
     */
    public function __construct(
        public array $missingPublishRequired,
        public array $missingContractRequired,
    ) {}

    public function isAgentComplete(): bool
    {
        return $this->missingContractRequired === [] && $this->missingPublishRequired === [];
    }

    public function blocksPublish(): bool
    {
        return $this->missingPublishRequired !== [];
    }
}
