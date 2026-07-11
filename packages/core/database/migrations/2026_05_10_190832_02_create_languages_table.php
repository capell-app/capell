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
        Schema::create('languages', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code', 12);
            $table->string('flag', 12)->nullable();
            $table->json('meta')->nullable();
            $table->json('admin')->nullable();
            $table->string('locale')->nullable();
            $table->unsignedInteger('order')->default(0)->index();
            $table->boolean('default')->index()->default(0);
            $table->boolean('status')->index()->default(1);
            $table->userstamps();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['code', 'deleted_at'], 'languages_code_unique');
            $table->index(['default', 'order', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('languages');
    }
};
