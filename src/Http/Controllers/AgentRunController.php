<?php

namespace Whilesmart\AgentChat\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Whilesmart\AgentChat\Enums\AgentRunStatus;
use Whilesmart\AgentChat\Enums\ConcurrencyPolicy;
use Whilesmart\AgentChat\Export\CanvasExporters;
use Whilesmart\AgentChat\Http\Requests\StoreAgentRunRequest;
use Whilesmart\AgentChat\Models\AgentRun;
use Whilesmart\AgentChat\Runs\RunDispatcher;
use Whilesmart\AgentChat\Runs\RunExecutor;
use Whilesmart\Conversations\Enums\MessageDirection;
use Whilesmart\Conversations\Enums\MessageType;
use Whilesmart\OwnerAccess\Concerns\AuthorizesOwnerController;

class AgentRunController extends ApiController
{
    use AuthorizesOwnerController;

    public function __construct(protected RunDispatcher $dispatcher) {}

    public function index(Request $request): JsonResponse
    {
        $model = config('agent-chat.model', AgentRun::class);
        $query = $this->scopeAccessibleOwners($model::query(), $request->user());

        if ($request->filled('conversation_id')) {
            $query->where('conversation_id', $request->input('conversation_id'));
        }

        if (in_array($request->input('status'), AgentRunStatus::values(), true)) {
            $query->where('status', $request->input('status'));
        }

        $runs = $query->with(['promptMessage', 'replyMessage', 'currentAttempt'])->latest('id')->paginate((int) $request->input('per_page', 20));

        return $this->success($this->resourceClass()::collection($runs)->response()->getData(true));
    }

    public function show(Request $request, AgentRun $agentRun): JsonResponse
    {
        $this->authorizeAccessTo($agentRun, $request->user());

        return $this->success($this->resource($agentRun->load(['promptMessage', 'replyMessage', 'currentAttempt'])));
    }

    public function attempts(Request $request, AgentRun $agentRun): JsonResponse
    {
        $this->authorizeAccessTo($agentRun, $request->user());

        $attempts = $agentRun->attempts()->orderBy('number')->get();

        return $this->success($this->resourceClass('attempt')::collection($attempts)->resolve($request));
    }

    /**
     * Posts the person's message and starts or holds a run. The author is the
     * signed-in user, never a value from the request, because the run executes
     * with that user's access.
     */
    public function store(StoreAgentRunRequest $request, $conversation): JsonResponse
    {
        if ($this->dispatcher->policy() === ConcurrencyPolicy::Reject && $this->dispatcher->busy($conversation->getKey())) {
            return $this->failure('Wait for the current answer to finish before asking again.', Response::HTTP_CONFLICT);
        }

        $user = $request->user();
        $hold = $request->boolean('hold');

        $run = DB::transaction(function () use ($request, $conversation, $user, $hold) {
            $prompt = $conversation->messages()->create([
                'author_type' => $user?->getMorphClass(),
                'author_id' => $user?->getAuthIdentifier(),
                'type' => MessageType::Text,
                'direction' => MessageDirection::Inbound,
                'body' => $request->validated('body'),
                'attachments' => $request->attachments(),
                'metadata' => $request->promptMetadata(),
            ]);

            $model = config('agent-chat.model', AgentRun::class);

            return $model::create([
                'owner_type' => $conversation->owner_type,
                'owner_id' => $conversation->owner_id,
                'requester_type' => $user?->getMorphClass(),
                'requester_id' => $user?->getAuthIdentifier(),
                'conversation_id' => $conversation->getKey(),
                'prompt_message_id' => $prompt->getKey(),
                'harness' => $request->harness(),
                'language' => $request->validated('language'),
                'options' => $request->options(),
                'status' => $hold ? AgentRunStatus::Held : AgentRunStatus::Queued,
                'queued_at' => $hold ? null : now(),
            ]);
        });

        if (! $hold) {
            $this->dispatcher->queue($run);
        }

        return $this->success($this->resource($run->fresh()), Response::HTTP_ACCEPTED);
    }

    public function release(Request $request, AgentRun $agentRun): JsonResponse
    {
        return $this->requeue($request, $agentRun, AgentRunStatus::Held, 'Only a held run can be released.', [
            'queued_at' => now(),
        ]);
    }

    /**
     * Only the person who asked can retry, because the run executes with their
     * access. The status change is one conditional update, so two retries of
     * the same run queue it once.
     */
    public function retry(Request $request, AgentRun $agentRun): JsonResponse
    {
        return $this->requeue($request, $agentRun, AgentRunStatus::Failed, 'Only a failed run can be retried.', [
            'error' => null,
            'progress' => null,
            'queued_at' => now(),
            'started_at' => null,
            'completed_at' => null,
        ]);
    }

    public function cancel(Request $request, AgentRun $agentRun, RunExecutor $executor): JsonResponse
    {
        $this->authorizeAccessTo($agentRun, $request->user());

        if (! $agentRun->isRequestedBy($request->user())) {
            return $this->failure('Only the person who asked can cancel this run.', Response::HTTP_FORBIDDEN);
        }

        if (! $executor->cancel($agentRun)) {
            return $this->failure('This run has already finished.', Response::HTTP_CONFLICT);
        }

        return $this->success($this->resource($agentRun->fresh()));
    }

    public function export(Request $request, AgentRun $agentRun, CanvasExporters $exporters): Response
    {
        $this->authorizeAccessTo($agentRun, $request->user());

        $format = (string) $request->query('format', 'md');

        if (! $exporters->has($format)) {
            return $this->failure("Unknown export format {$format}.", Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $canvas = $agentRun->load('replyMessage')->canvas();

        if ($canvas === null) {
            return $this->failure('This run has no canvas to export.', Response::HTTP_NOT_FOUND);
        }

        $exporter = $exporters->for($format);
        $title = (string) ($canvas['title'] ?? 'Report');
        $name = (str($title)->slug()->toString() ?: 'report').'.'.$exporter->extension();

        return response($exporter->export($title, (array) ($canvas['blocks'] ?? [])), 200, [
            'Content-Type' => $exporter->mimeType().'; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$name}\"",
        ]);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    protected function requeue(Request $request, AgentRun $agentRun, AgentRunStatus $from, string $conflict, array $changes): JsonResponse
    {
        $user = $request->user();
        $this->authorizeAccessTo($agentRun, $user);

        if (! $agentRun->isRequestedBy($user)) {
            return $this->failure('Only the person who asked can do this.', Response::HTTP_FORBIDDEN);
        }

        $model = config('agent-chat.model', AgentRun::class);
        $moved = $model::query()
            ->whereKey($agentRun->getKey())
            ->where('status', $from->value)
            ->update(['status' => AgentRunStatus::Queued->value, ...$changes]);

        if ($moved !== 1) {
            return $this->failure($conflict, Response::HTTP_CONFLICT);
        }

        $run = $agentRun->fresh();
        $this->dispatcher->queue($run);

        return $this->success($this->resource($run->fresh()), Response::HTTP_ACCEPTED);
    }
}
