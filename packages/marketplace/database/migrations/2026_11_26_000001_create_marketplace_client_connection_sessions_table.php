<?php

declare(strict_types=1);

use Capell\Core\Support\TableName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Marketplace client used to keep its outgoing account-connection handshake in
 * `marketplace_account_connection_sessions`, the same name the Marketplace server uses for
 * its incoming sessions. A host that runs both (capell.app) records the client's create
 * migration as already run against the server-shaped table, so starting a connection fails.
 * The client now owns `marketplace_client_connection_sessions`: an ordinary site's client
 * table is renamed with its rows, and a server-shaped table is never touched.
 */
return new class extends Migration
{
    private const string LEGACY = 'marketplace_account_connection_sessions';

    private const string CLIENT = 'marketplace_client_connection_sessions';

    public function up(): void
    {
        if (Schema::hasTable(TableName::resolve(self::CLIENT))) {
            return;
        }

        if ($this->legacyTableIsClientShaped()) {
            $this->dropIndexes(self::LEGACY, 'mp_account_sessions_connection_id_unique');
            Schema::rename(TableName::resolve(self::LEGACY), TableName::resolve(self::CLIENT));
            $this->addIndexes(self::CLIENT, 'mp_client_sessions_connection_id_unique');

            return;
        }

        Schema::create(TableName::resolve(self::CLIENT), function (Blueprint $table): void {
            $table->id();
            $table->string('connection_session_id')->nullable();
            $table->string('state_hash', 64);
            $table->string('code_verifier_hash', 64);
            $table->text('code_verifier_encrypted');
            $table->string('claimed_domain');
            $table->string('app_url', 512);
            $table->string('callback_url', 512);
            $table->string('status')->default('pending');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        $this->addIndexes(self::CLIENT, 'mp_client_sessions_connection_id_unique');
    }

    public function down(): void
    {
        if (! Schema::hasTable(TableName::resolve(self::CLIENT))) {
            return;
        }

        if (Schema::hasTable(TableName::resolve(self::LEGACY))) {
            Schema::drop(TableName::resolve(self::CLIENT));

            return;
        }

        $this->dropIndexes(self::CLIENT, 'mp_client_sessions_connection_id_unique');
        Schema::rename(TableName::resolve(self::CLIENT), TableName::resolve(self::LEGACY));
        $this->addIndexes(self::LEGACY, 'mp_account_sessions_connection_id_unique');
    }

    /** Only the client's own columns qualify; the Marketplace server's table has none of them. */
    private function legacyTableIsClientShaped(): bool
    {
        $legacy = TableName::resolve(self::LEGACY);

        return Schema::hasTable($legacy)
            && Schema::hasColumns($legacy, ['state_hash', 'code_verifier_hash', 'code_verifier_encrypted', 'claimed_domain', 'callback_url']);
    }

    private function addIndexes(string $baseTable, string $connectionIdIndex): void
    {
        Schema::table(TableName::resolve($baseTable), function (Blueprint $table) use ($baseTable, $connectionIdIndex): void {
            $table->unique('connection_session_id', TableName::namedIndex($baseTable, $connectionIdIndex));
            $table->unique('state_hash', TableName::index($baseTable, 'state_hash', 'unique'));
            $table->index('claimed_domain', TableName::index($baseTable, 'claimed_domain', 'index'));
            $table->index('status', TableName::index($baseTable, 'status', 'index'));
        });
    }

    /** Index names embed the table name, so they are dropped before a rename and recreated under the new name. */
    private function dropIndexes(string $baseTable, string $connectionIdIndex): void
    {
        $table = TableName::resolve($baseTable);
        $unique = [TableName::namedIndex($baseTable, $connectionIdIndex), TableName::index($baseTable, 'state_hash', 'unique')];
        $plain = [TableName::index($baseTable, 'claimed_domain', 'index'), TableName::index($baseTable, 'status', 'index')];

        Schema::table($table, function (Blueprint $blueprint) use ($table, $unique, $plain): void {
            foreach ($unique as $name) {
                if (Schema::hasIndex($table, $name)) {
                    $blueprint->dropUnique($name);
                }
            }

            foreach ($plain as $name) {
                if (Schema::hasIndex($table, $name)) {
                    $blueprint->dropIndex($name);
                }
            }
        });
    }
};
