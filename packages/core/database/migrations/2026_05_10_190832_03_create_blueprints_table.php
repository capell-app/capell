<?php

declare(strict_types=1);

use Capell\Core\Support\TableName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (TableName::existsAndIsOwned('blueprints', basename(__FILE__, '.php'))) {
            return;
        }

        Schema::create(TableName::resolve('blueprints'), function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('type', 64);
            $table->string('key', 128);
            $table->string('group')->nullable();
            $table->json('meta')->nullable();
            $table->json('admin')->nullable();
            $table->string('component')->nullable()->index(TableName::index('blueprints', 'component', 'index'));
            $table->string('component_item')->nullable()->index(TableName::index('blueprints', 'component_item', 'index'));
            $table->boolean('is_livewire')->nullable();
            $table->string('view_file')->nullable();
            $table->unsignedInteger('order')->default(0)->index(TableName::index('blueprints', 'order', 'index'));
            $table->boolean('default')->default(0);
            $table->boolean('status')->default(1);
            $table->userstamps();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['type', 'key'], TableName::index('blueprints', ['type', 'key'], 'unique'));
            $table->index(['type', 'default'], TableName::index('blueprints', ['type', 'default'], 'index'));
            $table->index(['type', 'status'], TableName::index('blueprints', ['type', 'status'], 'index'));
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(TableName::resolve('blueprints'));
    }
};
