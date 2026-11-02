<?php

declare(strict_types=1);

namespace Capell\Core\Events;

use Capell\Core\Data\PublicModelWithdrawalData;

final class FrontendSurrogateKeysInvalidated
{
    /** @var list<string> */
    public array $surrogateKeys;

    /**
     * @param  array<int, string>  $surrogateKeys
     * @param  PublicModelWithdrawalData|null  $withdrawal  Set when the keys are invalidated because this model left
     *                                                      public view. A listener that can withdraw exactly that
     *                                                      model's cached pages may do so instead of clearing by key.
     */
    public function __construct(
        array $surrogateKeys,
        public readonly ?PublicModelWithdrawalData $withdrawal = null,
    ) {
        $this->surrogateKeys = array_values(array_unique(array_filter(
            $surrogateKeys,
            fn (string $surrogateKey): bool => $surrogateKey !== '',
        )));
    }
}
