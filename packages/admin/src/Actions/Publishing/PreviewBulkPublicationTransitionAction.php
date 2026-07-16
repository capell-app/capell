<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Publishing;

use Capell\Admin\Data\Publishing\BulkPublicationPreviewData;
use Capell\Core\Actions\Publishing\EvaluatePublicationTransitionAction;
use Capell\Core\Contracts\Publishing\AuthorizesPublicationTransition;
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

final class PreviewBulkPublicationTransitionAction
{
    use AsFake;
    use AsObject;

    public function __construct(
        private readonly AuthorizesPublicationTransition $authorizer,
        private readonly EvaluatePublicationTransitionAction $evaluator,
    ) {}

    /** @param Collection<int, Model&Publishable> $records */
    public function handle(
        Collection $records,
        Authenticatable $actor,
        PublicationTransition $transition,
        CarbonImmutable $now,
        ?CarbonImmutable $requestedTime = null,
    ): BulkPublicationPreviewData {
        $evaluated = [];
        $counts = array_fill_keys(array_column(PublicationTransitionOutcome::cases(), 'value'), 0);

        foreach ($records as $record) {
            $request = new PublicationTransitionRequestData(
                record: $record,
                transition: $transition,
                actor: $actor,
                now: $now,
                requestedTime: $requestedTime,
            );
            $result = $this->authorizer->allows($request)
                ? EvaluatePublicationTransitionAction::run($request)
                : $this->evaluator->unchanged(
                    $request,
                    PublicationTransitionOutcome::Unauthorized,
                    'publication.transition.unauthorized',
                );
            $counts[$result->outcome->value]++;
            $evaluated[] = [
                'id' => $this->key($record),
                'label' => $this->label($record),
                'result' => $result,
            ];
        }

        return new BulkPublicationPreviewData($evaluated, $counts);
    }

    private function key(Model $record): int|string
    {
        $key = $record->getKey();

        return is_int($key) || is_string($key) ? $key : (string) $key;
    }

    private function label(Model $record): string
    {
        foreach (['name', 'title', 'label'] as $attribute) {
            $value = $record->getAttribute($attribute);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return sprintf('%s #%s', class_basename($record), $this->key($record));
    }
}
