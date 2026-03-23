<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->text('topic_summary')->nullable()->after('title');
            $table->boolean('topic_locked')->default(false)->after('topic_summary');
            $table->string('mediation_phase')->default('opening')->after('topic_locked');
            $table->text('session_summary')->nullable()->after('mediation_phase');
            $table->unsignedInteger('human_message_count')->default(0)->after('session_summary');
            $table->text('creator_context')->nullable()->after('human_message_count');
        });
    }

    public function down(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->dropColumn([
                'topic_summary',
                'topic_locked',
                'mediation_phase',
                'session_summary',
                'human_message_count',
                'creator_context',
            ]);
        });
    }
};
