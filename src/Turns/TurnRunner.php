<?php

namespace Whilesmart\AgentChat\Turns;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Throwable;
use Whilesmart\AgentChat\Blocks\BlockBuilder;
use Whilesmart\AgentChat\Blocks\BlockCollector;
use Whilesmart\AgentChat\Contracts\TurnInputBuilder;
use Whilesmart\AgentChat\Enums\AgentTurnStatus;
use Whilesmart\AgentChat\Events\AgentTurnCompleted;
use Whilesmart\AgentChat\Events\AgentTurnFailed;
use Whilesmart\AgentChat\Events\AgentTurnProgressed;
use Whilesmart\AgentChat\Events\AgentTurnUpdated;
use Whilesmart\AgentChat\Models\AgentTurn;
use Whilesmart\Agents\AgentManager;
use Whilesmart\Agents\Enums\StreamEventType;
use Whilesmart\Agents\ValueObjects\AgentStreamEvent;
use Whilesmart\Agents\ValueObjects\ToolContext;
use Whilesmart\Conversations\Enums\MessageDirection;
use Whilesmart\Conversations\Enums\MessageType;
use Whilesmart\OwnerAccess\Contracts\OwnerAuthorizer;

/**
 * Runs one agent turn: streams the harness, records progress as tools run,
 * then posts the reply to the conversation as a message whose metadata holds
 * the blocks.
 */
class TurnRunner
{
    public function __construct(
        private TurnInputBuilder $input,
        private BlockBuilder $blocks,
        private ProgressLabels $labels,
    ) {}

    /**
     * Take a turn for this worker. Only a queued turn, or a running turn whose
     * worker has outlived the job timeout, can be claimed, and the claim is a
     * single conditional update so two workers never run the same turn.
     */
    public function claim(int|string $turnId): ?AgentTurn
    {
        $model = config('agent-chat.model', AgentTurn::class);
        $stale = now()->subSeconds((int) config('agent-chat.queue.timeout', 120) + 30);

        $claimed = $model::query()
            ->whereKey($turnId)
            ->where(fn ($query) => $query
                ->where('status', AgentTurnStatus::Queued->value)
                ->orWhere(fn ($query) => $query
                    ->where('status', AgentTurnStatus::Running->value)
                    ->where('started_at', '<', $stale)))
            ->update(['status' => AgentTurnStatus::Running->value, 'started_at' => now(), 'error' => null]);

        return $claimed === 1 ? $model::query()->find($turnId) : null;
    }

    /**
     * Run a claimed turn. Returns false when the attempt failed but the queue
     * may try again: the turn goes back to queued instead of failing.
     */
    public function run(AgentTurn $turn, bool $finalAttempt = true): bool
    {
        if ($turn->reply_message_id !== null) {
            return true;
        }

        if (! $this->requesterMayAct($turn)) {
            $this->fail($turn, 'The person who asked no longer has access to this conversation.');

            return true;
        }

        $this->broadcast($turn, 'started');

        $collector = new BlockCollector;
        app()->instance(BlockCollector::class, $collector);

        $result = $this->agents()->stream(
            $turn->harness,
            $this->input->build($turn),
            $this->context($turn),
            fn (AgentStreamEvent $event) => $this->progress($turn, $event),
        );

        if (! $result->ok || $result->finishReason === 'error') {
            $error = $result->ok ? 'The answer was cut off before it finished.' : ($result->error ?? 'The agent could not finish this turn.');

            if (! $finalAttempt) {
                $turn->forceFill(['status' => AgentTurnStatus::Queued, 'started_at' => null, 'progress' => null, 'error' => $error])->save();

                return false;
            }

            $this->fail($turn, $error);

            return true;
        }

        $blocks = $this->compose($result->text, $collector);

        DB::transaction(function () use ($turn, $result, $blocks) {
            $reply = $turn->conversation->messages()->create([
                'type' => MessageType::Text,
                'direction' => MessageDirection::Outbound,
                'body' => $result->text,
                'metadata' => ['agent_turn_id' => $turn->getKey(), 'harness' => $turn->harness, 'blocks' => $blocks],
            ]);

            $turn->forceFill([
                'status' => AgentTurnStatus::Completed,
                'reply_message_id' => $reply->getKey(),
                'tool_calls' => $result->toolCalls,
                'usage' => $result->usage,
                'error' => null,
                'completed_at' => now(),
            ])->save();
        });

        AgentTurnCompleted::dispatch($turn);
        $this->broadcast($turn, 'settled');

        return true;
    }

    /**
     * Access is checked again at run time: the turn may have waited in the
     * queue while the requester lost access to the owner.
     */
    private function requesterMayAct(AgentTurn $turn): bool
    {
        $requester = $turn->requester;

        return app(OwnerAuthorizer::class)->authorize(
            $requester instanceof Authenticatable ? $requester : null,
            (string) $turn->owner_type,
            $turn->owner_id,
        );
    }

    private function agents(): AgentManager
    {
        return app('agents');
    }

    public function fail(AgentTurn $turn, string $error): void
    {
        $turn->forceFill([
            'status' => AgentTurnStatus::Failed,
            'error' => $error,
            'completed_at' => now(),
        ])->save();

        AgentTurnFailed::dispatch($turn);
        $this->broadcast($turn, 'settled');
    }

    /**
     * Narration first, then the drawn blocks. When the agent opened a canvas,
     * the drawn blocks become one document and the chat keeps the lead-in.
     *
     * @return array<int, array<string, mixed>>
     */
    private function compose(string $text, BlockCollector $collector): array
    {
        $blocks = trim($text) === '' ? [] : [$this->blocks->markdown(trim($text))];
        $drawn = $collector->all();

        if ($collector->canvasTitle() !== null && $drawn !== []) {
            $blocks[] = $this->blocks->canvas($collector->canvasTitle(), $drawn);

            return $blocks;
        }

        return [...$blocks, ...$drawn];
    }

    private function context(AgentTurn $turn): ToolContext
    {
        $requester = $turn->requester;

        return new ToolContext(
            $requester instanceof Authenticatable ? $requester : null,
            app()->getLocale(),
            [
                'conversation_id' => $turn->conversation_id,
                'agent_turn_id' => $turn->getKey(),
                'owner_type' => $turn->owner_type,
                'owner_id' => $turn->owner_id,
            ],
        );
    }

    private function progress(AgentTurn $turn, AgentStreamEvent $event): void
    {
        if ($event->type !== StreamEventType::ToolCall || $event->toolName === null) {
            return;
        }

        $label = $this->labels->for($event->toolName);
        $steps = $turn->progress ?? [];

        if ($label === null || end($steps) === $label) {
            return;
        }

        $steps[] = $label;
        $turn->forceFill(['progress' => $steps])->save();

        AgentTurnProgressed::dispatch($turn, $label);
        $this->broadcast($turn, 'progress', $label);
    }

    private function broadcast(AgentTurn $turn, string $kind, ?string $label = null): void
    {
        if (! config('agent-chat.broadcast.enabled', false)) {
            return;
        }

        try {
            event(new AgentTurnUpdated($turn->conversation_id, $turn->getKey(), $kind, $label));
        } catch (Throwable) {
            // A broadcaster that is down must not fail the turn; clients fall back to polling.
        }
    }
}
