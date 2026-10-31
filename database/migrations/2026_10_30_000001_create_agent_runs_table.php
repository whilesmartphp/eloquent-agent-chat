<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $uuids = (bool) config('conversations.uuids', false);

        Schema::create(config('agent-chat.runs_table', 'agent_runs'), function (Blueprint $table) use ($uuids) {
            if ($uuids) {
                $table->uuid('id')->primary();
                $table->uuidMorphs('owner');
                $table->nullableUuidMorphs('requester');
                $table->uuid('conversation_id');
                $table->uuid('prompt_message_id')->nullable();
                $table->uuid('reply_message_id')->nullable();
                $table->uuid('current_attempt_id')->nullable();
            } else {
                $table->id();
                $table->morphs('owner');
                $table->nullableMorphs('requester');
                $table->unsignedBigInteger('conversation_id');
                $table->unsignedBigInteger('prompt_message_id')->nullable();
                $table->unsignedBigInteger('reply_message_id')->nullable();
                $table->unsignedBigInteger('current_attempt_id')->nullable();
            }

            $table->string('harness');
            $table->string('status')->default('queued');
            $table->string('language', 20)->nullable();
            $table->json('options')->nullable();
            $table->json('progress')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['owner_type', 'owner_id', 'status']);
            $table->index(['conversation_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('agent-chat.runs_table', 'agent_runs'));
    }
};
