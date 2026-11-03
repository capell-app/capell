<?php

declare(strict_types=1);

use Capell\Core\Support\TableName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(TableName::resolve('public_read_eligibility_state'), function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('revision', 64);
            $table->timestamp('updated_at')->nullable();
        });
        DB::table(TableName::resolve('public_read_eligibility_state'))->insert([
            'id' => 1,
            'revision' => (string) Str::ulid(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists(TableName::resolve('public_read_eligibility_state'));
    }
};
