<?php

declare(strict_types=1);

namespace Capell\Frontend\Contracts\Fragments;

use Capell\Frontend\Data\Fragments\PublicFragmentReferenceData;

interface PublicFragmentUrlResolver
{
    public const string TAG = 'capell.frontend.public-fragment-url-resolver';

    public function owner(): string;

    public function url(PublicFragmentReferenceData $reference): string;
}
