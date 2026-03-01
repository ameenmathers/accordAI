<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_id')->constrained()->cascadeOnDelete();
            // The email address that was invited (may or may not have an account yet)
            $table->string('invited_email');
            // Unique token used in the invitation link
            $table->string('token')->unique();
            // Set when the invitee accepts — null means still pending
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->index(['chat_id', 'invited_email']);
        });

        // Widen status column to allow 'waiting' alongside 'active' and 'finalized'
        Schema::table('chats', function (Blueprint $table) {
            $table->string('status')->default('active')->change();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_invitations');
    }
};
