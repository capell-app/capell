<?php

declare(strict_types=1);

namespace Capell\Frontend\Contracts;

use Illuminate\Database\Eloquent\Model;

interface DeferredFragmentReferenceBuilder
{
    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public function reference(Model $asset, array $meta): array;

    /**
     * @param  array<string, mixed>  $reference
     */
    public function url(array $reference): string;
}
