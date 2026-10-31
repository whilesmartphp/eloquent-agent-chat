<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $uuids = (bool) config('conversations.uuids', false);
        $runsTable = config('agent-chat.runs_table', 'agent_runs');

        Schema::create(config('agent-chat.attempts_table', 'agent_run_attempts'), function (Blueprint $table) use ($uuids, $runsTable) {
            if ($uuids) {
                $table->uuid('id')->primary();
                $table->foreignUuid('run_id')->constrained($runsTable)->cascadeOnDelete();
                $table->uuid('history_until_message_id')->nullable();
            } else {
                $table->id();
                $table->foreignId('run_id')->constrained($runsTable)->cascadeOnDelete();
                $table->unsignedBigInteger('history_until_message_id')->nullable();
            }

            $table->unsignedInteger('number');
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->string('status')->default('running');
            $table->string('finish_reason')->nullable();
            $table->text('error')->nullable();
            $table->boolean('retryable')->nullable();
            $table->json('tool_calls')->nullable();
            $table->json('usage')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['run_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('agent-chat.attempts_table', 'agent_run_attempts'));
    }
};
