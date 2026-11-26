<?php

declare(strict_types=1);

use Capell\Core\Support\TableName;
use Capell\Marketplace\Actions\StartMarketplaceAccountConnectionAction;
use Capell\Marketplace\Enums\MarketplaceAccountConnectionSessionStatus;
use Capell\Marketplace\Models\MarketplaceAccountConnectionSession;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

function marketplaceMigration(string $basename): mixed
{
    return require dirname(__DIR__, 3) . '/database/migrations/' . $basename . '.php';
}

function clientConnectionSessionsMigration(): mixed
{
    return marketplaceMigration('2026_11_26_000001_create_marketplace_client_connection_sessions_table');
}

/** @return list<string> */
function clientConnectionSessionIndexNames(string $baseTable): array
{
    $names = array_map(static fn (array $index): string => $index['name'], Schema::getIndexes(TableName::resolve($baseTable)));
    sort($names);

    return array_values(array_filter($names, static fn (string $name): bool => $name !== 'primary'));
}

beforeEach(function (): void {
    Schema::dropIfExists(TableName::resolve('marketplace_client_connection_sessions'));
    Schema::dropIfExists(TableName::resolve('marketplace_account_connection_sessions'));
});

it('creates the client table beside a Marketplace server table without touching it, so account setup starts', function (): void {
    // capell.app runs the Marketplace server: its migrations turn the legacy table into the server's incoming-session shape.
    $legacy = TableName::resolve('marketplace_account_connection_sessions');
    Schema::create($legacy, function (Blueprint $table): void {
        $table->id();
        $table->string('connection_session_id')->unique('mp_account_sessions_connection_id_unique');
        $table->unsignedBigInteger('user_id');
        $table->string('claimed_domain');
        $table->string('app_url', 512);
        $table->string('callback_url', 512);
        $table->text('state_encrypted');
        $table->string('code_challenge', 128);
        $table->string('authorization_code_hash', 128)->nullable();
        $table->string('status')->default('pending');
        $table->timestamps();
    });
    DB::table($legacy)->insert([
        'connection_session_id' => 'mcs_server', 'user_id' => 7, 'claimed_domain' => 'customer.test',
        'app_url' => 'https://customer.test', 'callback_url' => 'https://customer.test/callback',
        'state_encrypted' => 'encrypted', 'code_challenge' => 'challenge',
    ]);

    clientConnectionSessionsMigration()->up();

    expect(Schema::hasColumns($legacy, ['user_id', 'authorization_code_hash', 'state_encrypted']))->toBeTrue()
        ->and(Schema::hasColumn($legacy, 'state_hash'))->toBeFalse()
        ->and(DB::table($legacy)->pluck('connection_session_id')->all())->toBe(['mcs_server'])
        ->and(clientConnectionSessionIndexNames('marketplace_client_connection_sessions'))->toBe([
            'marketplace_client_connection_sessions_claimed_domain_index',
            'marketplace_client_connection_sessions_state_hash_unique',
            'marketplace_client_connection_sessions_status_index',
            'mp_client_sessions_connection_id_unique',
        ]);

    config([
        'app.url' => 'https://capell.test',
        'capell-marketplace.marketplace.base_url' => 'https://marketplace.test/api',
    ]);
    Http::fake(['https://marketplace.test/api/marketplace/connections' => Http::response(['data' => [
        'connection_session_id' => 'mcs_client',
        'approval_url' => 'https://marketplace.test/marketplace/connect/mcs_client',
    ]], 201)]);

    expect(StartMarketplaceAccountConnectionAction::run())->toBe('https://marketplace.test/marketplace/connect/mcs_client')
        ->and(MarketplaceAccountConnectionSession::query()->sole()->connection_session_id)->toBe('mcs_client')
        ->and(DB::table($legacy)->count())->toBe(1);

    clientConnectionSessionsMigration()->down();

    expect(Schema::hasTable(TableName::resolve('marketplace_client_connection_sessions')))->toBeFalse()
        ->and(DB::table($legacy)->pluck('connection_session_id')->all())->toBe(['mcs_server']);
});

it("moves an ordinary site's client sessions to the client table with their rows, and back on rollback", function (): void {
    marketplaceMigration('2026_05_10_190837_07_create_marketplace_account_connection_sessions_table')->up();
    $legacy = TableName::resolve('marketplace_account_connection_sessions');
    DB::table($legacy)->insert([
        'connection_session_id' => 'mcs_pending', 'state_hash' => hash('sha256', 'state'),
        'code_verifier_hash' => hash('sha256', 'verifier'), 'code_verifier_encrypted' => 'ciphertext',
        'claimed_domain' => 'customer.test', 'app_url' => 'https://customer.test',
        'callback_url' => 'https://customer.test/callback', 'status' => 'pending',
    ]);

    clientConnectionSessionsMigration()->up();
    clientConnectionSessionsMigration()->up();

    $session = MarketplaceAccountConnectionSession::query()->sole();
    expect(Schema::hasTable($legacy))->toBeFalse()
        ->and($session->connection_session_id)->toBe('mcs_pending')
        ->and($session->state_hash)->toBe(hash('sha256', 'state'))
        ->and($session->status)->toBe(MarketplaceAccountConnectionSessionStatus::Pending)
        ->and(clientConnectionSessionIndexNames('marketplace_client_connection_sessions'))->toBe([
            'marketplace_client_connection_sessions_claimed_domain_index',
            'marketplace_client_connection_sessions_state_hash_unique',
            'marketplace_client_connection_sessions_status_index',
            'mp_client_sessions_connection_id_unique',
        ]);

    clientConnectionSessionsMigration()->down();

    expect(Schema::hasTable(TableName::resolve('marketplace_client_connection_sessions')))->toBeFalse()
        ->and(DB::table($legacy)->pluck('connection_session_id')->all())->toBe(['mcs_pending'])
        ->and(clientConnectionSessionIndexNames('marketplace_account_connection_sessions'))->toBe([
            'marketplace_account_connection_sessions_claimed_domain_index',
            'marketplace_account_connection_sessions_state_hash_unique',
            'marketplace_account_connection_sessions_status_index',
            'mp_account_sessions_connection_id_unique',
        ]);
});
