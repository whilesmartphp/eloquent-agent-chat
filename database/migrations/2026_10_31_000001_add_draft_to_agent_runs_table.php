<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(config('agent-chat.runs_table', 'agent_runs'), function (Blueprint $table) {
            $table->json('draft')->nullable()->after('progress');
        });
    }

    public function down(): void
    {
        Schema::table(config('agent-chat.runs_table', 'agent_runs'), function (Blueprint $table) {
            $table->dropColumn('draft');
        });
    }
};
