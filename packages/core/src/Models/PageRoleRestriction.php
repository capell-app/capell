<?php

declare(strict_types=1);

namespace Capell\Core\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\Permission\Models\Role;

/**
 * Restricts pages of a given type to users who hold the given role
 * within the page's site scope.
 *
 * @property int $id
 * @property string $restrictable_type
 * @property int $restrictable_id
 * @property int $role_id
 */
class PageRoleRestriction extends Model
{
    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    protected $fillable = [
        'restrictable_type',
        'restrictable_id',
        'role_id',
    ];

    /** @return MorphTo<Model, $this> */
    public function restrictable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
