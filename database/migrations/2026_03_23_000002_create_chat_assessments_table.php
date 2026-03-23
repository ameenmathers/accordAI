<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('at_message_count');
            $table->string('mediation_phase');
            $table->text('core_issue')->nullable();
            $table->json('sub_issues')->nullable();
            $table->json('resolved_agreements')->nullable();
            $table->text('progress_assessment')->nullable();
            $table->text('recommended_technique')->nullable();
            $table->text('recommended_phase')->nullable();
            $table->json('participant_states')->nullable();
            $table->timestamps();

            $table->index(['chat_id', 'at_message_count']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_assessments');
    }
};
