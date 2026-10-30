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
        if (TableName::existsAndIsOwned('block_templates', basename(__FILE__, '.php'))) {
            return;
        }

        Schema::create(TableName::resolve('block_templates'), function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique(TableName::index('block_templates', 'key', 'unique'));
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('blocks');
            $table->boolean('enabled')->default(true)->index(TableName::index('block_templates', 'enabled', 'index'));
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(TableName::resolve('block_templates'));
    }
};
