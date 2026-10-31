<?php

namespace Whilesmart\AgentChat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Whilesmart\AgentChat\Enums\AgentRunAttemptStatus;

class AgentRunAttempt extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'status' => AgentRunAttemptStatus::class,
        'retryable' => 'boolean',
        'tool_calls' => 'array',
        'usage' => 'array',
        'lease_expires_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => 'running',
    ];

    protected static function booted(): void
    {
        static::creating(function (AgentRunAttempt $attempt) {
            if (config('conversations.uuids', false) && empty($attempt->{$attempt->getKeyName()})) {
                $attempt->{$attempt->getKeyName()} = (string) Str::orderedUuid();
            }
        });
    }

    public function getIncrementing(): bool
    {
        return ! config('conversations.uuids', false);
    }

    public function getKeyType(): string
    {
        return config('conversations.uuids', false) ? 'string' : 'int';
    }

    public function getTable(): string
    {
        return config('agent-chat.attempts_table', 'agent_run_attempts');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(config('agent-chat.model', AgentRun::class), 'run_id');
    }
}
