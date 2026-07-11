<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table): void {
            $table->dateTime('visible_from')->nullable()->change();
            $table->dateTime('visible_until')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table): void {
            $table->timestamp('visible_from')->nullable()->change();
            $table->timestamp('visible_until')->nullable()->change();
        });
    }
};
