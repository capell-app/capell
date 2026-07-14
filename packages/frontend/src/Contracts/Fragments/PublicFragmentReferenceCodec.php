<?php

declare(strict_types=1);

namespace Capell\Frontend\Contracts\Fragments;

use Capell\Frontend\Data\Fragments\PublicFragmentReferenceData;

interface PublicFragmentReferenceCodec
{
    public function encode(PublicFragmentReferenceData $reference): string;

    public function decode(string $token): PublicFragmentReferenceData;
}
