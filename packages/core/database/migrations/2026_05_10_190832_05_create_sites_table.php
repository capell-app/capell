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
        Schema::create('sites', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->foreignId('blueprint_id')->constrained();
            $table->foreignId('theme_id')->constrained();
            $table->foreignId('language_id')->constrained();
            $table->json('meta')->nullable();
            $table->json('admin')->nullable();
            $table->unsignedInteger('order')->default(0)->index();
            $table->boolean('default')->index()->default(0);
            $table->boolean('status')->index()->default(1);
            $table->userstamps();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sites');
    }
};
