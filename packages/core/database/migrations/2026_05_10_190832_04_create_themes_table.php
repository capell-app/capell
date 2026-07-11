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
        Schema::create('themes', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->foreignId('blueprint_id')->constrained();
            $table->string('key', 128);
            $table->string('active_key', 128)->nullable()->unique();
            $table->longText('custom_css')->nullable();
            $table->json('meta')->nullable();
            $table->json('meta_extra')->nullable();
            $table->json('admin')->nullable();
            $table->unsignedInteger('order')->default(0)->index();
            $table->boolean('default')->index()->default(0);
            $table->boolean('status')->index()->default(1);
            $table->userstamps();
            $table->timestamps();
            $table->unique(['key', 'deleted_at']);
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('themes');
    }
};
