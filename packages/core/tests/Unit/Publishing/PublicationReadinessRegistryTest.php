<?php

declare(strict_types=1);

use Capell\Core\Contracts\Publishing\PublicationReadinessContributor;
use Capell\Core\Data\Publishing\PublicationReadinessCheckData;
use Capell\Core\Data\Publishing\PublicationReadinessContextData;
use Capell\Core\Enums\PublishStatusEnum;
use Capell\Core\Enums\PublishVisibilityStateEnum;
use Capell\Core\Models\Contracts\Publishable;
use Capell\Core\Support\Publishing\PublicationReadinessRegistry;
use Capell\Core\Tests\Support\TaggedReadinessContributor;
use Carbon\CarbonImmutable;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

function readinessContributor(bool $supports, array $checks, ?Closure $capture = null): PublicationReadinessContributor
{
    return new readonly class($supports, $checks, $capture) implements PublicationReadinessContributor
    {
        /** @param list<PublicationReadinessCheckData> $checksToReturn */
        public function __construct(private bool $supportsRecord, private array $checksToReturn, private ?Closure $capture) {}

        public function supports(Model&Publishable $record): bool
        {
            return $this->supportsRecord;
        }

        /** @return list<PublicationReadinessCheckData> */
        public function checks(Model&Publishable $record, PublicationReadinessContextData $context): array
        {
            if ($this->capture instanceof Closure) {
                ($this->capture)($context);
            }

            return $this->checksToReturn;
        }
    };
}

it('returns an empty result for an unconfigured model', function (): void {
    $model = publicationReadinessTestModel();

    expect((new PublicationReadinessRegistry)->blockingCheckIds($model, new PublicationReadinessContextData(1, 2)))->toBe([]);
});

it('discovers and validates tagged contributors lazily', function (): void {
    $container = new Container;
    $container->instance(TaggedReadinessContributor::class, new TaggedReadinessContributor);
    $container->tag(TaggedReadinessContributor::class, PublicationReadinessContributor::TAG);

    expect(new PublicationReadinessRegistry($container)->blockingCheckIds(publicationReadinessTestModel(), new PublicationReadinessContextData(1, 2)))
        ->toBe(['tagged.check']);
});

it('retries tagged discovery after a validation failure', function (): void {
    $container = new Container;
    $container->instance('invalid', new stdClass);
    $container->instance(TaggedReadinessContributor::class, new TaggedReadinessContributor);
    $container->tag(['invalid', TaggedReadinessContributor::class], PublicationReadinessContributor::TAG);

    $registry = new PublicationReadinessRegistry($container);

    expect(fn (): array => $registry->contributors())->toThrow(LogicException::class)
        ->and(fn (): array => $registry->contributors())->toThrow(LogicException::class);
});

it('preserves contributor ordering and isolates the explicit context', function (): void {
    $contexts = [];
    $registry = new PublicationReadinessRegistry;
    $registry->register(readinessContributor(true, [new PublicationReadinessCheckData('first')], function ($context) use (&$contexts): void {
        $contexts[] = $context;
    }));
    $registry->register(readinessContributor(true, [new PublicationReadinessCheckData('second', false)]));

    $checks = $registry->checks(publicationReadinessTestModel(), new PublicationReadinessContextData(7, 11));

    expect(array_map(fn (PublicationReadinessCheckData $check): string => $check->id, $checks))->toBe(['first', 'second'])
        ->and($registry->blockingCheckIds(publicationReadinessTestModel(), new PublicationReadinessContextData(8, 12)))->toBe(['first'])
        ->and($contexts[0]->siteId)->toBe(7)
        ->and($contexts[0]->languageId)->toBe(11);
});

it('rejects duplicate stable check identities', function (): void {
    $registry = new PublicationReadinessRegistry;
    $registry->register(readinessContributor(true, [new PublicationReadinessCheckData('same')]));
    $registry->register(readinessContributor(true, [new PublicationReadinessCheckData('same')]));

    expect(fn (): array => $registry->checks(publicationReadinessTestModel(), new PublicationReadinessContextData(1, 1)))
        ->toThrow(InvalidArgumentException::class);
});

/** @return Model&Publishable */
function publicationReadinessTestModel(): Model
{
    return new class extends Model implements Publishable
    {
        use HasFactory;

        #[Override]
        public function trashed(): bool
        {
            return false;
        }

        #[Override]
        public function isExpired(): bool
        {
            return false;
        }

        #[Override]
        public function isPending(): bool
        {
            return false;
        }

        #[Override]
        public function getPublishStatus(): PublishStatusEnum
        {
            return PublishStatusEnum::published;
        }

        #[Override]
        public function publishVisibilityState(?CarbonImmutable $now = null): PublishVisibilityStateEnum
        {
            return PublishVisibilityStateEnum::published;
        }
    };
}
