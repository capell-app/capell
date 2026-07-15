<?php

declare(strict_types=1);

namespace Capell\Frontend\Data\Assets;

use Capell\Core\Enums\PresentationLoadingStrategy;
use InvalidArgumentException;
use Spatie\LaravelData\Data;

final class FrontendResourceActivationData extends Data
{
    public function __construct(
        public readonly string $target,
        public readonly PresentationLoadingStrategy $loadingStrategy,
    ) {
        throw_if($target === '' || preg_match('/\A[a-zA-Z0-9_-]+\z/', $target) !== 1, InvalidArgumentException::class, 'Frontend resource activation targets must be opaque public tokens.');
    }
}
