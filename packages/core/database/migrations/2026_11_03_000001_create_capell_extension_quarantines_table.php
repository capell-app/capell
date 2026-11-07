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
        Schema::create(TableName::resolve('capell_extension_quarantines'), function (Blueprint $table): void {
            $table->id();
            $table->string('composer_name');
            $table->string('failing_package');
            $table->json('quarantined_set');
            $table->string('failing_comparison')->nullable();
            $table->string('expected_digest')->nullable();
            $table->string('actual_digest')->nullable();
            $table->dateTime('quarantined_at');
            $table->timestamps();

            $table->unique('composer_name', TableName::index('capell_extension_quarantines', 'composer_name', 'unique'));
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(TableName::resolve('capell_extension_quarantines'));
    }
};
