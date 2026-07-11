<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Activity;

use Capell\Admin\Data\Activity\ActivityResourceLinkData;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Core\Actions\GetEditPageResourceUrlAction;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Translation;
use Closure;
use Filament\Resources\Resource as FilamentResource;
use Illuminate\Database\Eloquent\Model;
use Throwable;

final class ActivityResourceLinkRegistry
{
    /**
     * @var array<class-string<Model>, array{
     *     resourceClass: class-string<FilamentResource>|null,
     *     relation: string|null,
     *     resolver: Closure|null
     * }>
     */
    private array $definitions = [];

    public function __construct()
    {
        $this->registerDefaults();
    }

    /**
     * @param  class-string<Model>  $subjectClass
     * @param  class-string<FilamentResource>|null  $resourceClass
     */
    public function register(
        string $subjectClass,
        ?string $resourceClass = null,
        ?string $relation = null,
        ?Closure $recordResolver = null,
    ): void {
        $this->definitions[$subjectClass] = [
            'resourceClass' => $resourceClass,
            'relation' => $relation,
            'resolver' => $recordResolver,
        ];
    }

    public function clear(): void
    {
        $this->definitions = [];

        $this->registerDefaults();
    }

    public function resolve(Model $subject): ?ActivityResourceLinkData
    {
        $definition = $this->definitionFor($subject);
        $record = $this->recordFor($subject, $definition);

        if (! $record instanceof Model) {
            return null;
        }

        /** @var class-string<FilamentResource>|null $resourceClass */
        $resourceClass = $definition['resourceClass'] ?? null;

        $usedIndexFallback = false;
        $url = $resourceClass !== null
            ? $this->resourceUrl($resourceClass, $record, $usedIndexFallback)
            : $this->automaticUrl($record, $resourceClass, $usedIndexFallback);

        if ($resourceClass === null && $url === null) {
            return null;
        }

        return new ActivityResourceLinkData(
            subject: $subject,
            record: $record,
            resourceClass: $resourceClass,
            url: $url,
            labelBasis: $this->labelBasis($record),
            usedProxyRecord: $record !== $subject,
            usedIndexFallback: $usedIndexFallback,
        );
    }

    /**
     * @return array{
     *     resourceClass: class-string<FilamentResource>|null,
     *     relation: string|null,
     *     resolver: Closure|null
     * }|null
     */
    private function definitionFor(Model $subject): ?array
    {
        $subjectClass = $subject::class;

        if (isset($this->definitions[$subjectClass])) {
            return $this->definitions[$subjectClass];
        }

        foreach ($this->definitions as $definedClass => $definition) {
            if ($subject instanceof $definedClass) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * @param  array{
     *     resourceClass: class-string<FilamentResource>|null,
     *     relation: string|null,
     *     resolver: Closure|null
     * }|null  $definition
     */
    private function recordFor(Model $subject, ?array $definition): ?Model
    {
        if ($definition === null) {
            return $subject;
        }

        $resolver = $definition['resolver'];

        if ($resolver instanceof Closure) {
            $record = $resolver($subject);

            return $record instanceof Model ? $record : null;
        }

        $relation = $definition['relation'];

        if (is_string($relation) && $relation !== '') {
            $record = $subject->getRelationValue($relation);

            return $record instanceof Model ? $record : null;
        }

        return $subject;
    }

    /**
     * @param  class-string<FilamentResource>|null  $resourceClass
     */
    private function automaticUrl(Model $record, ?string &$resourceClass, bool &$usedIndexFallback): ?string
    {
        if ($record instanceof Pageable) {
            $url = GetEditPageResourceUrlAction::run($record);

            if ($url !== null) {
                return $url;
            }
        }

        $resourceClass = $this->resourceForModel($record);

        if ($resourceClass === null) {
            return null;
        }

        return $this->resourceUrl($resourceClass, $record, $usedIndexFallback);
    }

    /**
     * @return class-string<FilamentResource>|null
     */
    private function resourceForModel(Model $record): ?string
    {
        foreach (CapellAdmin::getAdminSurfaceRegistry()->resources() as $resourceClass) {
            try {
                $modelClass = $resourceClass::getModel();
            } catch (Throwable) {
                continue;
            }

            if ($modelClass === $record::class || $record instanceof $modelClass) {
                return $resourceClass;
            }
        }

        return null;
    }

    /**
     * @param  class-string<FilamentResource>  $resourceClass
     */
    private function resourceUrl(string $resourceClass, Model $record, bool &$usedIndexFallback): ?string
    {
        try {
            return $resourceClass::getUrl('edit', ['record' => $record]);
        } catch (Throwable) {
            try {
                $usedIndexFallback = true;

                return $resourceClass::getUrl('index');
            } catch (Throwable) {
                return null;
            }
        }
    }

    private function labelBasis(Model $record): ?string
    {
        $label = $record->getAttribute('name')
            ?? $record->getAttribute('title')
            ?? $record->getKey();

        return $label === null ? null : (string) $label;
    }

    private function registerDefaults(): void
    {
        $this->register(
            subjectClass: Translation::class,
            relation: 'translatable',
        );
    }
}
