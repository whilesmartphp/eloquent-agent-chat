<?php

namespace Whilesmart\AgentChat\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Whilesmart\AgentChat\Database\Factories\AgentTurnFactory;
use Whilesmart\AgentChat\Enums\AgentTurnStatus;
use Whilesmart\Conversations\Models\Conversation;
use Whilesmart\Conversations\Models\Message;

class AgentTurn extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = ['id'];

    protected $casts = [
        'status' => AgentTurnStatus::class,
        'progress' => 'array',
        'tool_calls' => 'array',
        'usage' => 'array',
        'metadata' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => 'queued',
    ];

    protected static function booted(): void
    {
        static::creating(function (AgentTurn $turn) {
            if (config('conversations.uuids', false) && empty($turn->{$turn->getKeyName()})) {
                $turn->{$turn->getKeyName()} = (string) Str::orderedUuid();
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
        return config('agent-chat.turns_table', 'agent_turns');
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

    /**
     * @return array<int, array<string, mixed>>
     */
    public function blocks(): array
    {
        $blocks = $this->replyMessage?->metadata['blocks'] ?? [];

        return is_array($blocks) ? $blocks : [];
    }

    /**
     * The canvas block in this turn's reply, if the agent composed one.
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

    protected static function newFactory(): AgentTurnFactory
    {
        return AgentTurnFactory::new();
    }
}
