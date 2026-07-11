<?php

declare(strict_types=1);

namespace Capell\Core\Models;

use Capell\Core\Database\Factories\AssetAttachmentFactory;
use Capell\Core\Models\Concerns\HasUserstamps;
use Capell\Core\Models\Contracts\Userstampable;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Foundation\Auth\User as AuthenticatableUser;

/**
 * @property int $id
 * @property string $related_type
 * @property string $related_id
 * @property string $asset_type
 * @property string $asset_id
 * @property int $order
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property int|null $deleted_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Model $asset
 * @property-read AuthenticatableUser|null $creator
 * @property-read AuthenticatableUser|null $destroyer
 * @property-read AuthenticatableUser|null $editor
 * @property-read Model $related
 *
 * @method static AssetAttachmentFactory factory($count = null, $state = [])
 * @method static Builder<static>|AssetAttachment newModelQuery()
 * @method static Builder<static>|AssetAttachment newQuery()
 * @method static Builder<static>|AssetAttachment query()
 * @method static Builder<static>|AssetAttachment whereAssetId($value)
 * @method static Builder<static>|AssetAttachment whereAssetType($value)
 * @method static Builder<static>|AssetAttachment whereCreatedAt($value)
 * @method static Builder<static>|AssetAttachment whereCreatedBy($value)
 * @method static Builder<static>|AssetAttachment whereDeletedBy($value)
 * @method static Builder<static>|AssetAttachment whereId($value)
 * @method static Builder<static>|AssetAttachment whereOrder($value)
 * @method static Builder<static>|AssetAttachment whereRelatedId($value)
 * @method static Builder<static>|AssetAttachment whereRelatedType($value)
 * @method static Builder<static>|AssetAttachment whereUpdatedAt($value)
 * @method static Builder<static>|AssetAttachment whereUpdatedBy($value)
 *
 * @mixin Model
 */
class AssetAttachment extends Model implements Userstampable
{
    /** @use HasFactory<AssetAttachmentFactory> */
    use HasFactory;

    use HasUserstamps;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'asset_id',
        'asset_type',
        'order',
        'related_id',
        'related_type',
    ];

    protected static string $factory = AssetAttachmentFactory::class;

    /**
     * @return MorphTo<Model, $this>
     */
    public function related(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function asset(): MorphTo
    {
        return $this->morphTo();
    }
}
