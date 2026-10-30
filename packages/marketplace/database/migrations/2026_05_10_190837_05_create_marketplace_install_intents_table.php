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
        if (! TableName::existsAndIsOwned('marketplace_install_intents', basename(__FILE__, '.php'))) {
            Schema::create(TableName::resolve('marketplace_install_intents'), function (Blueprint $table): void {
                $table->id();
                $table->string('composer_name');
                $table->string('extension_slug');
                $table->string('extension_name');
                $table->string('kind');
                $table->string('status')->index(TableName::index('marketplace_install_intents', 'status', 'index'));
                $table->text('composer_command');
                $table->string('version_constraint')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();

                $table->unique(['composer_name', 'kind'], TableName::index('marketplace_install_intents', ['composer_name', 'kind'], 'unique'));
                $table->index(['kind', 'status'], TableName::index('marketplace_install_intents', ['kind', 'status'], 'index'));
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(TableName::resolve('marketplace_install_intents'));
    }
};
