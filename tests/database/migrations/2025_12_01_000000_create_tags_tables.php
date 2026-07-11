<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->default(0)->index();
            $table->json('name');
            $table->json('slug');
            $table->string('type')->nullable();
            $table->integer('order_column')->nullable();
            $table->boolean('featured')->index()->default(0);
            $table->boolean('status')->index()->default(1);
            $table->foreignId('site_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('taggables', function (Blueprint $table): void {
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('workspace_id')->default(0)->index();
            $table->morphs('taggable');
            $table->unique(['tag_id', 'taggable_id', 'taggable_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taggables');
        Schema::dropIfExists('tags');
    }
};
