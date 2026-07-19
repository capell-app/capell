<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Tokens;

use Capell\Core\Enums\ApiTokenAbility;
use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\NewAccessToken;
use LogicException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static NewAccessToken run(Authenticatable $user, string $name, list<string> $abilities)
 */
final class IssueApiTokenAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  list<string>  $abilities
     */
    public function handle(Authenticatable $user, string $name, array $abilities): NewAccessToken
    {
        $allowedAbilities = array_column(ApiTokenAbility::cases(), 'value');
        $validatedAbilities = [];

        foreach ($abilities as $ability) {
            throw_unless(
                in_array($ability, $allowedAbilities, true),
                InvalidArgumentException::class,
                sprintf('Unknown API token ability [%s].', $ability),
            );

            $validatedAbilities[] = $ability;
        }

        throw_unless(
            in_array(HasApiTokens::class, class_uses_recursive($user), true),
            LogicException::class,
            sprintf('%s must use Laravel Sanctum\'s HasApiTokens trait.', $user::class),
        );

        $accessToken = call_user_func([$user, 'createToken'], $name, $validatedAbilities);

        throw_unless($accessToken instanceof NewAccessToken, LogicException::class);

        return $accessToken;
    }
}
