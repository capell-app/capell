<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('marketplace_smoke_qa_records')) {
            return;
        }

        Schema::create('marketplace_smoke_qa_records', function (Blueprint $table): void {
            $table->id();
            $table->string('version')->default('1.0.0');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_smoke_qa_records');
    }
};
