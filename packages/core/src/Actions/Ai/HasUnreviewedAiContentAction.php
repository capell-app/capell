<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Ai;

use Capell\Core\Enums\AiDisclosure;
use Capell\Core\Models\AiProvenance;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Whether any field of the record was written or shaped by AI and nobody has
 * reviewed it since. Such a record must not go public until a person has.
 */
final class HasUnreviewedAiContentAction
{
    use AsFake;
    use AsObject;

    public function handle(Model $record): bool
    {
        return AiProvenance::query()
            ->where('pageable_type', $record->getMorphClass())
            ->where('pageable_id', $record->getKey())
            ->whereNull('confirmed_at')
            ->where('disclosure', '!=', AiDisclosure::None->value)
            ->exists();
    }
}
