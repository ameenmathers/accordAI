<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chats', function (Blueprint $table) {
            $table->id();
            // Context type describes the nature of the mediation
            // e.g. "relationship", "business", "family", "financial"
            $table->string('context_type');
            $table->string('title')->nullable();
            // Track who created the chat (will be a participant too)
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            // active = ongoing, finalized = memory extracted, closed
            $table->enum('status', ['active', 'finalized'])->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chats');
    }
};
