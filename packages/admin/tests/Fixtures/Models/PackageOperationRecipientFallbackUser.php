<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

final class PackageOperationRecipientFallbackUser extends Authenticatable
{
    use HasFactory;

    protected $table = 'users';

    public function isGlobalAdmin(): bool
    {
        return str_ends_with((string) $this->email, '@admin.example.test');
    }
}
