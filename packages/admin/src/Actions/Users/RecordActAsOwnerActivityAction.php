<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Users;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static void run(Authenticatable $supportUser, ?Authenticatable $ownerUser, string $event)
 */
final class RecordActAsOwnerActivityAction
{
    use AsObject;

    public const string LOG_NAME = 'support';

    public const string EVENT_STARTED = 'act_as_owner_started';

    public const string EVENT_STOPPED = 'act_as_owner_stopped';

    public function handle(Authenticatable $supportUser, ?Authenticatable $ownerUser, string $event): void
    {
        $logger = activity(self::LOG_NAME)
            ->event($event)
            ->withProperties([
                'support_user' => $this->userIdentity($supportUser),
                'owner_user' => $ownerUser instanceof Authenticatable ? $this->userIdentity($ownerUser) : null,
            ]);

        if ($supportUser instanceof Model) {
            $logger->causedBy($supportUser);
        }

        if ($ownerUser instanceof Model) {
            $logger->performedOn($ownerUser);
        }

        $logger->log($this->description($event));
    }

    /**
     * @return array{type: string, id: int|string|null}
     */
    private function userIdentity(Authenticatable $user): array
    {
        return [
            'type' => $user::class,
            'id' => $user->getAuthIdentifier(),
        ];
    }

    private function description(string $event): string
    {
        return match ($event) {
            self::EVENT_STARTED => (string) __('capell-admin::activity.act_as_owner_started'),
            self::EVENT_STOPPED => (string) __('capell-admin::activity.act_as_owner_stopped'),
            default => (string) __('capell-admin::activity.act_as_owner_recorded'),
        };
    }
}
