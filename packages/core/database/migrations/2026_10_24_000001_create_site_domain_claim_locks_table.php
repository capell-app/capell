<?php

declare(strict_types=1);

use Capell\Core\Support\TableName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(TableName::resolve('site_domain_claim_locks'), function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
        });
        DB::table(TableName::resolve('site_domain_claim_locks'))->insert(['id' => 1]);
    }

    public function down(): void
    {
        Schema::dropIfExists(TableName::resolve('site_domain_claim_locks'));
    }
};
