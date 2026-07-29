<?php

declare(strict_types=1);

namespace Capell\Installer\Actions;

use Capell\Core\Data\InstallInputData;
use Capell\Core\Data\NewUserData;
use Capell\Installer\Support\InstallerOptions;
use Capell\Installer\Support\InstallerSessionRepository;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

final class CacheInstallerSuccessSummaryAction
{
    use AsObject;

    public function __construct(
        private readonly InstallerSessionRepository $sessions,
        private readonly InstallerOptions $options,
    ) {}

    public function handle(string $installId, InstallInputData $inputData): void
    {
        $this->sessions->putSuccessSummary($installId, [
            'primaryAdmin' => $this->primaryAdminSummary($inputData),
            'roleUsersCreated' => $inputData->additionalUsers !== [],
        ]);
    }

    private function primaryAdminSummary(InstallInputData $inputData): ?string
    {
        if ($inputData->newUser instanceof NewUserData) {
            return sprintf('%s <%s>', $inputData->newUser->name, $inputData->newUser->email);
        }

        if ($inputData->userId === null || ! $this->options->usersTableExists()) {
            return null;
        }

        try {
            $userModel = $this->options->userModel();
            $user = $userModel::query()->find($inputData->userId, ['id', 'name', 'email']);

            if (! $user instanceof Model) {
                return null;
            }

            return sprintf('%s <%s>', (string) $user->getAttribute('name'), (string) $user->getAttribute('email'));
        } catch (Throwable) {
            return null;
        }
    }
}
