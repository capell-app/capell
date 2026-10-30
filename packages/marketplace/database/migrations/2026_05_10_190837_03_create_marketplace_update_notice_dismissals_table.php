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
        if (! TableName::existsAndIsOwned('marketplace_update_notice_dismissals', basename(__FILE__, '.php'))) {
            Schema::create(TableName::resolve('marketplace_update_notice_dismissals'), function (Blueprint $table): void {
                $table->id();
                $table->foreignUserId('user_id')->constrained('users', indexName: TableName::index('marketplace_update_notice_dismissals', 'user_id', 'foreign'))->cascadeOnDelete();
                $table->string('notice_id');
                $table->timestamp('dismissed_until')->nullable();
                $table->timestamps();
                $table->unique(['user_id', 'notice_id'], TableName::index('marketplace_update_notice_dismissals', ['user_id', 'notice_id'], 'unique'));
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(TableName::resolve('marketplace_update_notice_dismissals'));
    }
};
