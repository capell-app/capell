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
        Schema::create(TableName::resolve('public_read_owed_withdrawals'), function (Blueprint $table): void {
            $table->id();
            $table->string('revision', 64);
            $table->string('step', 120);
            $table->string('scope', 40);
            $table->unsignedBigInteger('site_id')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['revision', 'step', 'scope'], TableName::namedIndex('public_read_owed_withdrawals', 'public_read_owed_withdrawals_unique'));
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(TableName::resolve('public_read_owed_withdrawals'));
    }
};
