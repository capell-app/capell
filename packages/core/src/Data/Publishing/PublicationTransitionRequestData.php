<?php

declare(strict_types=1);

namespace Capell\Core\Data\Publishing;

use Capell\Core\Enums\Publishing\PublicationTransition;
use Capell\Core\Exceptions\InvalidPublicationTransitionRequest;
use Capell\Core\Models\Contracts\Publishable;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Spatie\LaravelData\Data;

final class PublicationTransitionRequestData extends Data
{
    public function __construct(
        public readonly Model&Publishable $record,
        public readonly PublicationTransition $transition,
        public readonly Authenticatable $actor,
        public readonly CarbonImmutable $now,
        public readonly ?CarbonImmutable $requestedTime = null,
    ) {
        if ($transition->requiresRequestedTime() && ! $requestedTime instanceof CarbonImmutable) {
            throw InvalidPublicationTransitionRequest::requestedTimeRequired();
        }

        if (! $transition->requiresRequestedTime() && $requestedTime instanceof CarbonImmutable) {
            throw InvalidPublicationTransitionRequest::requestedTimeForbidden();
        }
    }
}
