<?php

declare(strict_types=1);

namespace Capell\Core\Models\Concerns;

use Capell\Core\Models\Contracts\Userstampable;
use Capell\Core\Models\Scopes\UserstampsScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

/**
 * @mixin EloquentModel
 *
 * @original source from: Wildside\Userstamps\Userstamps
 *
 * @phpstan-require-implements Userstampable
 */
trait HasUserstamps
{
    protected bool $userstamping = true;

    public static function bootHasUserstamps(): void
    {
        static::addGlobalScope(new UserstampsScope);

        static::creating(function (EloquentModel&Userstampable $model): void {
            if (! $model->isUserstamping()) {
                return;
            }

            $createdBy = $model->getCreatedByColumn();
            $updatedBy = $model->getUpdatedByColumn();

            if ($model->getAttribute($createdBy) === null) {
                $model->setAttribute($createdBy, Auth::id());
            }

            if ($model->getAttribute($updatedBy) === null) {
                $model->setAttribute($updatedBy, Auth::id());
            }
        });

        static::updating(function (EloquentModel&Userstampable $model): void {
            if (! $model->isUserstamping() || Auth::id() === null) {
                return;
            }

            $model->setAttribute($model->getUpdatedByColumn(), Auth::id());
        });

        if (self::usingSoftDeletes()) {
            static::deleting(function (EloquentModel&Userstampable $model): void {
                if (! $model->isUserstamping()) {
                    return;
                }

                $deletedBy = $model->getDeletedByColumn();

                if ($model->getAttribute($deletedBy) === null) {
                    $model->setAttribute($deletedBy, Auth::id());
                }

                $dispatcher = $model->getEventDispatcher();
                $model->unsetEventDispatcher();
                $model->save();

                if ($dispatcher !== null) {
                    $model->setEventDispatcher($dispatcher);
                }
            });

            /** @phpstan-ignore-next-line method.notFound (only invoked when SoftDeletes is in use) */
            static::restoring(function (EloquentModel&Userstampable $model): void {
                if (! $model->isUserstamping()) {
                    return;
                }

                $model->setAttribute($model->getDeletedByColumn(), null);
            });
        }
    }

    public static function usingSoftDeletes(): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive(static::class), true);
    }

    public function getCreatedByColumn(): string
    {
        return defined('static::CREATED_BY') ? static::CREATED_BY : 'created_by';
    }

    public function getUpdatedByColumn(): string
    {
        return defined('static::UPDATED_BY') ? static::UPDATED_BY : 'updated_by';
    }

    public function getDeletedByColumn(): string
    {
        return defined('static::DELETED_BY') ? static::DELETED_BY : 'deleted_by';
    }

    public function getUserClass(): string
    {
        $class = config('auth.providers.users.model');

        return is_string($class) ? $class : '';
    }

    public function isUserstamping(): bool
    {
        return $this->userstamping;
    }

    public function stopUserstamping(): void
    {
        $this->userstamping = false;
    }

    public function startUserstamping(): void
    {
        $this->userstamping = true;
    }

    /**
     * @return BelongsTo<EloquentModel, $this>
     */
    public function creator(): BelongsTo
    {
        /** @var class-string<EloquentModel> $userClass */
        $userClass = $this->getUserClass();

        return $this->belongsTo($userClass, $this->getCreatedByColumn());
    }

    /**
     * @return BelongsTo<EloquentModel, $this>
     */
    public function editor(): BelongsTo
    {
        /** @var class-string<EloquentModel> $userClass */
        $userClass = $this->getUserClass();

        return $this->belongsTo($userClass, $this->getUpdatedByColumn());
    }

    /**
     * @return BelongsTo<EloquentModel, $this>
     */
    public function destroyer(): BelongsTo
    {
        /** @var class-string<EloquentModel> $userClass */
        $userClass = $this->getUserClass();

        return $this->belongsTo($userClass, $this->getDeletedByColumn());
    }

    public function createdAt(): ?CarbonImmutable
    {
        $value = $this->getAttribute('created_at');

        return $value instanceof CarbonImmutable ? $value : null;
    }

    public function updatedAt(): ?CarbonImmutable
    {
        $value = $this->getAttribute('updated_at');

        return $value instanceof CarbonImmutable ? $value : null;
    }

    public function deletedAt(): ?CarbonImmutable
    {
        $value = $this->getAttribute('deleted_at');

        return $value instanceof CarbonImmutable ? $value : null;
    }

    public function creatorUser(): ?EloquentModel
    {
        if (! $this->relationLoaded('creator')) {
            return null;
        }

        $relation = $this->getRelation('creator');

        return $relation instanceof EloquentModel ? $relation : null;
    }

    public function editorUser(): ?EloquentModel
    {
        if (! $this->relationLoaded('editor')) {
            return null;
        }

        $relation = $this->getRelation('editor');

        return $relation instanceof EloquentModel ? $relation : null;
    }

    public function destroyerUser(): ?EloquentModel
    {
        if (! $this->relationLoaded('destroyer')) {
            return null;
        }

        $relation = $this->getRelation('destroyer');

        return $relation instanceof EloquentModel ? $relation : null;
    }
}
