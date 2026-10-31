# Eloquent Agent Chat

Agent runs for [`whilesmart/eloquent-conversations`](https://github.com/whilesmartphp/eloquent-conversations), executed by a [`whilesmart/eloquent-agents`](https://github.com/whilesmartphp/eloquent-agents) harness.

A person posts a prompt, with optional attachments, to a conversation. The package stores it as a message, queues a run, executes the harness with that person's access, and posts the agent's reply back to the same conversation. The reply message holds typed blocks (tables, charts, headline numbers, images, a canvas document) and any files the agent produced. Every run is scoped to the conversation's polymorphic `owner` through [`whilesmart/eloquent-owner-access`](https://github.com/whilesmartphp/eloquent-owner-access).

The design behind it is in [docs/design.md](docs/design.md).

## Install

```bash
composer require whilesmart/eloquent-agent-chat
php artisan migrate
```

Register a harness named `assistant` (or set `agent-chat.default_harness`) and list the render tools it may use:

```php
class AssistantHarness extends \Whilesmart\Agents\Harness\AbstractHarness
{
    public function name(): string { return 'assistant'; }

    public function systemPrompt(?ToolContext $context = null): string { return '...'; }

    public function toolNames(): array
    {
        return ['your_read_tool', 'render_markdown', 'render_table', 'render_chart', 'render_kpi', 'open_canvas', 'update_canvas', 'ask_question'];
    }
}
```

Runs execute on the queue, so a worker must be running outside of tests.

## Concepts

| Piece | What it is |
|---|---|
| `AgentRun` | The request for one answer. Has an `owner`, a `requester`, the `conversation`, the `prompt_message` and `reply_message`, a `status`, `language`, `options`, `progress` steps and the latest `error`. |
| `AgentRunAttempt` | One execution with one provider and model: `status`, `finish_reason`, `error`, `retryable`, `tool_calls` and `usage` (null when the provider never reported it). |
| Reply message | An outbound conversation message with no author. `metadata.blocks` holds the blocks, `metadata.agent_run_id` links back to the run, and `attachments` holds the files the agent produced. |
| Blocks | Shapes built in code by `BlockBuilder`, never by the model: `markdown`, `table`, `kpi`, `chart`, `callout`, `timeline`, `progress`, `question`, `image`, `canvas`, plus the types you register. |
| Canvas | A titled document made of the blocks drawn after `open_canvas`. `update_canvas` hands the previous canvas back to the agent so it can edit rather than start over. |

## How a run executes

1. The prompt is saved as an inbound message authored by the signed-in user. The run starts `queued`, or `held` when the request sets `hold`.
2. A worker claims the run with one conditional update. A run left `running` by a worker that died is claimed again once `queue.timeout` has passed, and its unfinished attempt is marked abandoned.
3. The requester's access to the owner is checked again, since they may have lost it while the run waited.
4. Each candidate from the provider plan is tried as an attempt. A provider error or an answer cut off mid stream fails the attempt; if the retry policy allows, the next candidate is tried.
5. On success, the reply message is created in the same transaction that completes the run. Delivery to SMS or email is queued after that commit, so only a finished answer is ever sent. A run cancelled meanwhile discards its answer.
6. When every candidate failed, the run goes back to the queue after `queue.backoff` seconds until `queue.tries` is used up, then fails with the reason. A failed run can be retried by the person who asked.

States: `held`, `queued`, `running`, `waiting` (reserved for approvals), `completed`, `failed`, `cancelled`.

## Attachments

Prompt attachments are stored on the prompt message in this shape:

```json
{ "type": "image", "url": "https://files.example.com/ref.png", "mime": "image/png", "name": "ref.png", "size": 48213, "ref": { "type": "library_asset", "id": 7 } }
```

`type` is `image`, `document`, `audio`, `video` or `file`. A file is located by an https `url`, by `disk` and `path`, or only by a `ref` to a record in your app.

The media resolver fetches files on the server to show them to the model, so a person may only attach files from hosts listed in `attachments.url_hosts` or disks listed in `attachments.disks`. Both lists are empty by default. The default resolver shows the prompt's images and documents to the model; earlier attachments are only named in the history text. Bind your own `MediaResolver` to resolve `ref` attachments or resend earlier images.

A tool hands a file it produced to the run with the block collector. The file is attached to the reply message, so channels that send media deliver it, and an image is also shown as an `image` block that points at it:

```php
app(\Whilesmart\AgentChat\Blocks\BlockCollector::class)->attach([
    'type' => 'image',
    'url' => $asset->url,
    'mime' => 'image/png',
    'name' => 'poster.png',
    'ref' => ['type' => 'library_asset', 'id' => $asset->id],
], alt: 'The poster');
```

## API

Routes use the configured prefix (default `api`) and middleware (default `['api', 'auth:sanctum']`).

| Method | Path | Action |
|---|---|---|
| POST | `/conversations/{conversation}/agent-runs` | Post a prompt as the signed-in user: `body` and/or `attachments`, plus optional `harness` (from `agent-chat.harnesses`), `language`, `options` and `hold`. Returns 202. |
| GET | `/agent-runs` | List runs the user can reach. Filter by `conversation_id` and `status`. |
| GET | `/agent-runs/{run}` | Status, progress, error, the current attempt and the reply with its blocks and attachments. |
| GET | `/agent-runs/{run}/attempts` | Every attempt in order. |
| POST | `/agent-runs/{run}/release` | Start a held run. Requester only; 409 unless held. |
| POST | `/agent-runs/{run}/retry` | Queue a failed run again. Requester only, since the run executes with their access; 409 unless failed. |
| POST | `/agent-runs/{run}/cancel` | Cancel a run that has not settled. Requester only; 409 once settled. |
| GET | `/agent-runs/{run}/export?format=md` | Download the run's canvas. 404 when there is none. |

Post agent prompts through this endpoint rather than the conversations message endpoint. That endpoint accepts an author from the request body, and the agent acts with its author's access.

## Render tools

`render_markdown`, `render_table`, `render_kpi`, `render_chart`, `render_callout`, `render_timeline`, `render_progress`, `ask_question`, `open_canvas`, `update_canvas`. They carry the read permission because drawing changes no data. Each validates its input and returns an error to the agent, drawing nothing, when the input is unusable.

Your own tools add blocks with `BlockCollector::add()`. A type outside the core list must be listed in `agent-chat.block_types`. Point such blocks at your records with a `ref` rather than copying the record into the block.

A tool can report its own steps while it works:

```php
$progress = app(\Whilesmart\AgentChat\Runs\RunProgress::class);
$progress->start('Rendering the design');
// ...
$progress->finish();
// or, when it went wrong:
$progress->fail(detail: 'The template is missing.');
```

Each step in `progress` is `{label, state}` with `state` set to `running`, `done` or `failed`; a failed step may also carry a `detail`. When an attempt fails, the step it was on is marked failed. When the run gets its answer, any step still running is marked done, so a step never hangs.

## Events

| Event | When |
|---|---|
| `AgentRunQueued` | A run is queued, released or retried. |
| `AgentRunStarted` | A worker starts executing a run. |
| `AgentRunProgressed` | A new progress step starts. |
| `AgentRunAttemptFailed` | An attempt fails; the run may still try again. |
| `AgentRunCompleted` | The reply is posted. |
| `AgentRunFailed` | The run ends without a reply. |
| `AgentRunCancelled` | The requester cancels the run. |

With `agent-chat.broadcast.enabled`, `AgentRunUpdated` is also broadcast on `private-agent-chat.conversation.{id}` with `kind` set to `started`, `progress` or `settled`. Authorize that channel in your `routes/channels.php`.

## Customization

Everything below is set in `config/agent-chat.php` (`php artisan vendor:publish --tag=agent-chat-config`):

- `model`, `attempt_model`, `requests.store_run`, `resources.run`, `resources.attempt`, `response_formatter`, `controller`
- `route_actions`, `route_write_middleware`, `register_routes`
- `default_harness` and the `harnesses` a request may pick
- `input_builder`: a `RunInputBuilder` that decides what text the agent reads. The default folds in the last `history_limit` messages, labelling each speaker by where the message came from, and mentions an earlier canvas.
- `media_resolver`: a `MediaResolver` that turns attachments into media the model sees.
- `provider_plan`: a `ProviderPlan` listing the providers to try in order. Each candidate is passed to the harness as run overrides.
- `retry_policy`: a `RetryPolicy` that decides whether a failed attempt is worth another try.
- `concurrency`: `queue` (a prompt waits for the earlier run in its conversation), `reject` (409 while one is unfinished) or `parallel`.
- `attachments`: `max`, `url_hosts` and `disks`
- `tools`, `register_tools` and `block_types`
- `progress_labels`: step labels keyed by tool name pattern
- `exporters`: one `CanvasExporter` per format
- `queue`: connection, queue, tries, timeout and backoff (seconds between tries)

Tools receive the run's `language` as the context locale and its `options` under the `options` scope key.

## Testing

```bash
make bootstrap
docker compose run --rm app composer test
docker compose run --rm app composer pint:test
```

## License

MIT
