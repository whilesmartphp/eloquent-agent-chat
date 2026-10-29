<?php

namespace Whilesmart\AgentChat\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Whilesmart\AgentChat\Enums\AgentTurnStatus;
use Whilesmart\AgentChat\Events\AgentTurnQueued;
use Whilesmart\AgentChat\Export\CanvasExporters;
use Whilesmart\AgentChat\Http\Requests\StoreAgentTurnRequest;
use Whilesmart\AgentChat\Jobs\RunAgentTurn;
use Whilesmart\AgentChat\Models\AgentTurn;
use Whilesmart\AgentChat\Turns\TurnRunner;
use Whilesmart\Conversations\Enums\MessageDirection;
use Whilesmart\Conversations\Enums\MessageType;
use Whilesmart\OwnerAccess\Concerns\AuthorizesOwnerController;

class AgentTurnController extends ApiController
{
    use AuthorizesOwnerController;

    public function index(Request $request): JsonResponse
    {
        $model = config('agent-chat.model', AgentTurn::class);
        $query = $this->scopeAccessibleOwners($model::query(), $request->user());

        if ($request->filled('conversation_id')) {
            $query->where('conversation_id', $request->input('conversation_id'));
        }

        if (in_array($request->input('status'), AgentTurnStatus::values(), true)) {
            $query->where('status', $request->input('status'));
        }

        $turns = $query->with('replyMessage')->latest('id')->paginate((int) $request->input('per_page', 20));

        return $this->success($this->resourceClass()::collection($turns)->response()->getData(true));
    }

    public function show(Request $request, AgentTurn $agentTurn): JsonResponse
    {
        $this->authorizeAccessTo($agentTurn, $request->user());

        return $this->success($this->resource($agentTurn->load('replyMessage')));
    }

    /**
     * Posts the user's message and queues the agent's reply. The author is the
     * signed-in user, never a value from the request, because the turn runs
     * with that user's access.
     */
    public function store(StoreAgentTurnRequest $request, $conversation): JsonResponse
    {
        $user = $request->user();

        $turn = DB::transaction(function () use ($request, $conversation, $user) {
            $prompt = $conversation->messages()->create([
                'author_type' => $user?->getMorphClass(),
                'author_id' => $user?->getAuthIdentifier(),
                'type' => MessageType::Text,
                'direction' => MessageDirection::Inbound,
                'body' => $request->validated('body'),
            ]);

            $model = config('agent-chat.model', AgentTurn::class);

            return $model::create([
                'owner_type' => $conversation->owner_type,
                'owner_id' => $conversation->owner_id,
                'requester_type' => $user?->getMorphClass(),
                'requester_id' => $user?->getAuthIdentifier(),
                'conversation_id' => $conversation->getKey(),
                'prompt_message_id' => $prompt->getKey(),
                'harness' => $request->harness(),
                'status' => AgentTurnStatus::Queued,
            ]);
        });

        $this->queue($turn);

        return $this->success($this->resource($turn->fresh()), Response::HTTP_ACCEPTED);
    }

    /**
     * Only the person who asked can retry, because the turn runs with their
     * access. The status change is one conditional update, so two retries of
     * the same turn queue it once.
     */
    public function retry(Request $request, AgentTurn $agentTurn): JsonResponse
    {
        $user = $request->user();
        $this->authorizeAccessTo($agentTurn, $user);

        if ($user === null
            || $agentTurn->requester_type !== $user->getMorphClass()
            || (string) $agentTurn->requester_id !== (string) $user->getAuthIdentifier()) {
            return $this->failure('Only the person who asked can retry this turn.', Response::HTTP_FORBIDDEN);
        }

        $model = config('agent-chat.model', AgentTurn::class);
        $reset = $model::query()
            ->whereKey($agentTurn->getKey())
            ->where('status', AgentTurnStatus::Failed->value)
            ->update([
                'status' => AgentTurnStatus::Queued->value,
                'error' => null,
                'progress' => null,
                'started_at' => null,
                'completed_at' => null,
            ]);

        if ($reset !== 1) {
            return $this->failure('Only a failed turn can be retried.', Response::HTTP_CONFLICT);
        }

        $turn = $agentTurn->fresh();
        $this->queue($turn);

        return $this->success($this->resource($turn->fresh()), Response::HTTP_ACCEPTED);
    }

    /**
     * A turn whose job never reached the queue is marked failed, so the
     * requester can retry it instead of it waiting forever.
     */
    protected function queue(AgentTurn $turn): void
    {
        try {
            RunAgentTurn::dispatch($turn->getKey());
        } catch (Throwable $e) {
            report($e);
            app(TurnRunner::class)->fail($turn, 'The turn could not be queued. Try again.');

            return;
        }

        AgentTurnQueued::dispatch($turn);
    }

    public function export(Request $request, AgentTurn $agentTurn, CanvasExporters $exporters): Response
    {
        $this->authorizeAccessTo($agentTurn, $request->user());

        $format = (string) $request->query('format', 'md');

        if (! $exporters->has($format)) {
            return $this->failure("Unknown export format {$format}.", Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $canvas = $agentTurn->load('replyMessage')->canvas();

        if ($canvas === null) {
            return $this->failure('This turn has no canvas to export.', Response::HTTP_NOT_FOUND);
        }

        $exporter = $exporters->for($format);
        $title = (string) ($canvas['title'] ?? 'Report');
        $name = (str($title)->slug()->toString() ?: 'report').'.'.$exporter->extension();

        return response($exporter->export($title, (array) ($canvas['blocks'] ?? [])), 200, [
            'Content-Type' => $exporter->mimeType().'; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$name}\"",
        ]);
    }
}
