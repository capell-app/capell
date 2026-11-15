<?php

declare(strict_types=1);

namespace Capell\Core\Support\Publishing;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Whether an actor may make a record public, take it down, or sign off a review.
 * A record's policy that defines `publish` decides; a policy without it keeps the
 * older rule, where anyone who can update the record may publish it.
 */
final class PublishAbility
{
    public const string ABILITY = 'publish';

    public static function allows(Authenticatable $actor, Model $record): bool
    {
        $policy = Gate::getPolicyFor($record);
        $ability = is_object($policy) && method_exists($policy, self::ABILITY) ? self::ABILITY : 'update';

        return Gate::forUser($actor)->allows($ability, $record);
    }
}
