<?php

declare(strict_types=1);

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
        Schema::create('pages', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36)->nullable();
            $table->string('name');
            $table->foreignId('blueprint_id')->constrained();
            $table->foreignId('layout_id')->constrained();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->json('meta')->nullable();
            $table->json('admin')->nullable();
            $table->visibleDates();
            $table->unsignedInteger('order')->nullable();
            $table->userstamps();
            $table->timestamps();
            $table->softDeletes();
            $table->nestedSet();
            $table->nestedSetDepth();
            $table->nestedSetIndex();
            $table->index('uuid');
            $table->index(['site_id', 'blueprint_id']);
            $table->index(['site_id', 'order']);
            $table->index(['site_id', 'visible_from']);
            $table->index(['site_id', 'visible_until']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pages');
    }
};
