<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $uuids = (bool) config('conversations.uuids', false);

        Schema::create(config('agent-chat.turns_table', 'agent_turns'), function (Blueprint $table) use ($uuids) {
            if ($uuids) {
                $table->uuid('id')->primary();
                $table->uuidMorphs('owner');
                $table->nullableUuidMorphs('requester');
                $table->uuid('conversation_id');
                $table->uuid('prompt_message_id')->nullable();
                $table->uuid('reply_message_id')->nullable();
            } else {
                $table->id();
                $table->morphs('owner');
                $table->nullableMorphs('requester');
                $table->unsignedBigInteger('conversation_id');
                $table->unsignedBigInteger('prompt_message_id')->nullable();
                $table->unsignedBigInteger('reply_message_id')->nullable();
            }

            $table->string('harness');
            $table->string('status')->default('queued');
            $table->json('progress')->nullable();
            $table->json('tool_calls')->nullable();
            $table->json('usage')->nullable();
            $table->text('error')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['owner_type', 'owner_id', 'status']);
            $table->index(['conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('agent-chat.turns_table', 'agent_turns'));
    }
};
