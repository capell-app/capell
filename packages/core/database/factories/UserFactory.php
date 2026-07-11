<?php

declare(strict_types=1);

namespace Capell\Core\Database\Factories;

use Capell\Tests\Fixtures\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * The cached, hashed password reused across generated users.
     *
     * Hashing once at the configured cost keeps factories fast while staying
     * agnostic to BCRYPT_ROUNDS — a hard-coded hash breaks when the cost in
     * the hash exceeds the configured rounds.
     */
    protected static ?string $password = null;

    public function definition(): array
    {
        return [
            'name' => 'Test User ' . Str::random(8),
            'email' => fake()->userName() . '+' . Str::uuid()->toString() . '@example.test',
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }
}
