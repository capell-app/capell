<?php

declare(strict_types=1);

use Capell\Admin\Actions\Users\SetUserPreferredAdminLanguageAction;
use Capell\Core\Database\Factories\UserFactory;
use Capell\Core\Models\Language;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

it('sets a valid preferred admin language on the user', function (): void {
    $language = Language::factory()->english()->create(['status' => true]);
    $user = UserFactory::new()->createOne();

    SetUserPreferredAdminLanguageAction::run($user, $language->getKey());

    expect(expectPresent($user->fresh())->getAttribute('preferred_admin_language_id'))->toBe($language->getKey());
});

it('clears a blank preferred admin language', function (): void {
    $language = Language::factory()->english()->create(['status' => true]);
    $user = UserFactory::new()->createOne(['preferred_admin_language_id' => $language->getKey()]);

    SetUserPreferredAdminLanguageAction::run($user, null);

    expect(expectPresent($user->fresh())->getAttribute('preferred_admin_language_id'))->toBeNull();
});

it('rejects disabled languages', function (): void {
    $language = Language::factory()->english()->create(['status' => false]);
    $user = UserFactory::new()->createOne();

    SetUserPreferredAdminLanguageAction::run($user, $language->getKey());
})->throws(InvalidArgumentException::class);

it('rejects non numeric language ids', function (): void {
    $user = UserFactory::new()->createOne();

    SetUserPreferredAdminLanguageAction::run($user, 'english');
})->throws(InvalidArgumentException::class);

it('rejects decimal language ids instead of coercing them', function (): void {
    Language::factory()->english()->create(['status' => true]);
    $user = UserFactory::new()->createOne();

    SetUserPreferredAdminLanguageAction::run($user, '1.5');
})->throws(InvalidArgumentException::class);

it('fails cleanly when the model does not support admin language preferences', function (): void {
    $language = Language::factory()->english()->create(['status' => true]);
    $user = userWithoutPreferredAdminLanguageColumn();

    SetUserPreferredAdminLanguageAction::run($user, $language->getKey());
})->throws(InvalidArgumentException::class);

function userWithoutPreferredAdminLanguageColumn(): Model
{
    Schema::dropIfExists('users_without_preferred_admin_language');
    Schema::create('users_without_preferred_admin_language', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('email');
        $table->string('password');
    });

    $user = new class extends Model
    {
        /** @use HasFactory<Factory<static>> */
        use HasFactory;

        public $timestamps = false;

        protected $table = 'users_without_preferred_admin_language';

        protected $guarded = [];
    };

    $user->forceFill([
        'name' => 'Unsupported User',
        'email' => 'unsupported-user@example.test',
        'password' => 'password',
    ])->save();

    return $user;
}
