<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Publishing;

use Capell\Admin\Data\Publishing\BulkPublicationPreviewData;
use Capell\Core\Actions\Publishing\TransitionPublicationAction;
use Capell\Core\Data\Publishing\PublicationTransitionRequestData;
use Capell\Core\Enums\Publishing\PublicationTransition;
use Capell\Core\Enums\Publishing\PublicationTransitionOutcome;
use Capell\Core\Models\Contracts\Publishable;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class RunBulkPublicationTransitionAction
{
    use AsFake;
    use AsObject;

    /** @param Collection<int, Model&Publishable> $records */
    public function handle(
        Collection $records,
        Authenticatable $actor,
        PublicationTransition $transition,
        CarbonImmutable $now,
        ?CarbonImmutable $requestedTime = null,
    ): BulkPublicationPreviewData {
        $executed = [];
        $counts = array_fill_keys(array_column(PublicationTransitionOutcome::cases(), 'value'), 0);

        foreach ($records as $record) {
            $result = TransitionPublicationAction::run(new PublicationTransitionRequestData(
                record: $record,
                transition: $transition,
                actor: $actor,
                now: $now,
                requestedTime: $requestedTime,
            ));
            $counts[$result->outcome->value]++;
            $key = $record->getKey();
            $executed[] = [
                'id' => is_int($key) || is_string($key) ? $key : (string) $key,
                'label' => $this->label($record),
                'result' => $result,
            ];
        }

        return new BulkPublicationPreviewData($executed, $counts);
    }

    private function label(Model $record): string
    {
        $value = $record->getAttribute('name');

        return is_string($value) && $value !== ''
            ? $value
            : sprintf('%s #%s', class_basename($record), (string) $record->getKey());
    }
}
