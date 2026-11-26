<?php

declare(strict_types=1);

use Capell\Core\Support\TableName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = TableName::resolve('admin_login_link_tokens');

        if (Schema::hasTable($table)) {
            return;
        }

        // One live sign-in link per person. Only a keyed hash of the token is
        // stored, and the password fingerprint ends the link when the password
        // changes, so a copy of this table cannot sign anyone in.
        Schema::create($table, function (Blueprint $table): void {
            $table->string('user_id')->primary();
            $table->string('token_hash', 64);
            $table->string('password_fingerprint', 64);
            $table->timestamp('expires_at')->index();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(TableName::resolve('admin_login_link_tokens'));
    }
};
