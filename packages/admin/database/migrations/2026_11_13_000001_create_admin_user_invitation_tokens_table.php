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
        // Kept apart from password_reset_tokens so a normal reset token can never
        // redeem an invitation, and an invitation's longer lifetime never applies
        // to a reset. A row means the invitee has not yet chosen a password.
        Schema::create(TableName::resolve('admin_user_invitation_tokens'), function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(TableName::resolve('admin_user_invitation_tokens'));
    }
};
