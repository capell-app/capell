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
        TableName::existsAndIsOwned('pages', '2026_05_10_190832_12_create_pages_table');

        Schema::table(TableName::resolve('pages'), function (Blueprint $table): void {
            $table->dateTime('visible_from')->nullable()->change();
            $table->dateTime('visible_until')->nullable()->change();
        });
    }

    public function down(): void
    {
        TableName::existsAndIsOwned('pages', '2026_05_10_190832_12_create_pages_table');

        Schema::table(TableName::resolve('pages'), function (Blueprint $table): void {
            $table->timestamp('visible_from')->nullable()->change();
            $table->timestamp('visible_until')->nullable()->change();
        });
    }
};
