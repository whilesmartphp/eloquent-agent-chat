<?php

namespace Whilesmart\AgentChat\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Whilesmart\AgentChat\Database\Factories\AgentRunFactory;
use Whilesmart\AgentChat\Enums\AgentRunStatus;
use Whilesmart\Conversations\Models\Conversation;
use Whilesmart\Conversations\Models\Message;

class AgentRun extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = ['id'];

    protected $casts = [
        'status' => AgentRunStatus::class,
        'options' => 'array',
        'progress' => 'array',
        'draft' => 'array',
        'queued_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => 'queued',
    ];

    protected static function booted(): void
    {
        static::creating(function (AgentRun $run) {
            if (config('conversations.uuids', false) && empty($run->{$run->getKeyName()})) {
                $run->{$run->getKeyName()} = (string) Str::orderedUuid();
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
        return config('agent-chat.runs_table', 'agent_runs');
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function requester(): MorphTo
    {
        return $this->morphTo();
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(config('conversations.model', Conversation::class), 'conversation_id');
    }

    public function promptMessage(): BelongsTo
    {
        return $this->belongsTo(config('conversations.message_model', Message::class), 'prompt_message_id');
    }

    public function replyMessage(): BelongsTo
    {
        return $this->belongsTo(config('conversations.message_model', Message::class), 'reply_message_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(config('agent-chat.attempt_model', AgentRunAttempt::class), 'run_id');
    }

    public function currentAttempt(): BelongsTo
    {
        return $this->belongsTo(config('agent-chat.attempt_model', AgentRunAttempt::class), 'current_attempt_id');
    }

    public function isRequestedBy(mixed $user): bool
    {
        return $user !== null
            && $this->requester_type === $user->getMorphClass()
            && (string) $this->requester_id === (string) $user->getAuthIdentifier();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function blocks(): array
    {
        $blocks = $this->replyMessage?->metadata['blocks'] ?? [];

        return is_array($blocks) ? $blocks : [];
    }

    /**
     * The canvas block in this run's reply, if the agent composed one.
     *
     * @return array<string, mixed>|null
     */
    public function canvas(): ?array
    {
        foreach ($this->blocks() as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'canvas') {
                return $block;
            }
        }

        return null;
    }

    protected static function newFactory(): AgentRunFactory
    {
        return AgentRunFactory::new();
    }
}
