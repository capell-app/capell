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
        Schema::create(TableName::resolve('property_sets'), function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique(TableName::index('property_sets', 'key', 'unique'));
            $table->string('name');
            $table->unsignedInteger('version')->default(1);
            $table->string('owner_package')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(TableName::resolve('property_sets'));
    }
};
