<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_invitations', function (Blueprint $table) {
            // For username-based invites: references the invited registered user
            $table->foreignId('invited_user_id')->nullable()->after('chat_id')
                ->constrained('users')->nullOnDelete();

            // Shareable links have no email; email now optional
            $table->string('invited_email')->nullable()->change();

            // Allow declining in addition to accepting
            $table->timestamp('declined_at')->nullable()->after('accepted_at');
        });
    }

    public function down(): void
    {
        Schema::table('chat_invitations', function (Blueprint $table) {
            $table->dropForeign(['invited_user_id']);
            $table->dropColumn(['invited_user_id', 'declined_at']);
            $table->string('invited_email')->nullable(false)->change();
        });
    }
};
