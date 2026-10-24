<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support\Models;

use Capell\Tests\Fixtures\Models\User;
use Illuminate\Auth\MustVerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Override;

class RetriableInstallUser extends User implements MustVerifyEmailContract
{
    use MustVerifyEmail;

    public static ?string $testConnection = 'installer_atomicity';

    protected $table = 'users';

    #[Override]
    public function getConnectionName(): ?string
    {
        return self::$testConnection;
    }

    #[Override]
    public function getMorphClass(): string
    {
        return 'user';
    }
}
