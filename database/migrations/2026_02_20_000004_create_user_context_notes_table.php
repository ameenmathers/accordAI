<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // This table acts as our simple "vector memory" store.
        // Instead of a real vector DB, we store short behavioral traits
        // extracted by Claude (Anthropic) after a chat is finalized.
        // These are retrieved by user_id + context_type to enrich AI prompts.
        Schema::create('user_context_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Scoped to context type so relationship traits don't bleed into business chats
            $table->string('context_type');
            // Short extracted behavioral trait (1-3 sentences max)
            $table->text('trait');
            // Reference back to the source chat for auditability
            $table->unsignedBigInteger('source_chat_id')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'context_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_context_notes');
    }
};
