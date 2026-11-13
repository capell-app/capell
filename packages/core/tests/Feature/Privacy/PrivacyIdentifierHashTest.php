<?php

declare(strict_types=1);

use Capell\Core\Data\Privacy\PrivacySubject;
use Capell\Core\Models\Site;
use Capell\Core\Support\Privacy\PrivacyIdentifierHasher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    config([
        'capell.privacy.identifier_key' => 'current-identifier-key',
        'capell.privacy.identifier_previous_keys' => ['earlier-identifier-key'],
    ]);
});

it('hashes an email address with the identifier key, ignoring case and spaces', function (): void {
    $hash = PrivacySubject::forEmail(' Jane@Example.TEST ')->emailHash();

    expect($hash)->toBe(hash_hmac('sha256', 'jane@example.test', 'current-identifier-key'))
        ->and(PrivacyIdentifierHasher::hash('jane@example.test'))->toBe($hash)
        ->and($hash)->not->toBe(hash('sha256', 'jane@example.test'))
        ->and(PrivacySubject::forModel(Site::factory()->createOne())->emailHash())->toBeNull();

    config(['capell.privacy.identifier_key' => 'another-key']);

    expect(PrivacyIdentifierHasher::hash('jane@example.test'))->not->toBe($hash);
});

it('falls back to the application key and its previous keys', function (): void {
    config([
        'capell.privacy.identifier_key' => null,
        'app.key' => 'application-key',
        'app.previous_keys' => ['earlier-application-key'],
    ]);

    expect(PrivacyIdentifierHasher::candidates('jane@example.test'))->toBe([
        hash_hmac('sha256', 'jane@example.test', 'application-key'),
        hash_hmac('sha256', 'jane@example.test', 'earlier-application-key'),
    ]);
});

it('refuses to hash without a key rather than use a guessable one', function (): void {
    config(['capell.privacy.identifier_key' => '', 'app.key' => '']);

    expect(fn (): string => PrivacyIdentifierHasher::hash('jane@example.test'))->toThrow(RuntimeException::class, 'CAPELL_PRIVACY_IDENTIFIER_KEY');
});

it('finds rows stored under the current or a previous key without the address reaching the query', function (): void {
    Schema::create('privacy_hash_entries', function (Blueprint $table): void {
        $table->id();
        $table->string('email_hash');
    });
    DB::table('privacy_hash_entries')->insert([
        ['email_hash' => hash_hmac('sha256', 'jane@example.test', 'current-identifier-key')],
        ['email_hash' => hash_hmac('sha256', 'jane@example.test', 'earlier-identifier-key')],
        ['email_hash' => hash_hmac('sha256', 'jane@example.test', 'unrelated-key')],
        ['email_hash' => hash_hmac('sha256', 'john@example.test', 'current-identifier-key')],
    ]);

    $query = DB::table('privacy_hash_entries');
    PrivacySubject::forEmail('JANE@example.test')->whereEmailHash($query, 'email_hash');
    $withoutEmail = DB::table('privacy_hash_entries');
    PrivacySubject::forModel(Site::factory()->createOne())->whereEmailHash($withoutEmail, 'email_hash');

    expect($query->pluck('id')->all())->toBe([1, 2])
        ->and(json_encode([$query->toSql(), $query->getBindings()], JSON_THROW_ON_ERROR))->not->toContain('example.test')
        ->and($withoutEmail->count())->toBe(0);
});
