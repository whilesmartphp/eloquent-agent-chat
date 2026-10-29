# Eloquent Agent Chat

Agent turns for [`whilesmart/eloquent-conversations`](https://github.com/whilesmartphp/eloquent-conversations), run by a [`whilesmart/eloquent-agents`](https://github.com/whilesmartphp/eloquent-agents) harness.

A user posts to a conversation. The package stores the message, queues a turn, runs the harness with the user's access, and posts the agent's reply back to the same conversation. The reply message holds typed blocks (tables, charts, headline numbers, a canvas document) that the agent drew with the package's render tools. Every turn is scoped to the conversation's polymorphic `owner` through [`whilesmart/eloquent-owner-access`](https://github.com/whilesmartphp/eloquent-owner-access).

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

Turns run on the queue, so a worker must be running outside of tests.

A turn runs once. A worker claims it with a single conditional update, and a turn left `running` by a worker that died is claimed again once `queue.timeout` has passed. Before running, the requester's access to the owner is checked again, because they may have lost it while the turn waited. A provider failure, or an answer cut off mid stream, puts the turn back on the queue after `queue.backoff` seconds until `queue.tries` is used up; the last attempt fails the turn with the reason. A turn whose job could not be queued is failed at once, so it can be retried.

## Concepts

| Piece | What it is |
|---|---|
| `AgentTurn` | One run of the agent. Has an `owner`, a `requester`, the `conversation`, the `prompt_message` and `reply_message`, a `status` (queued, running, completed, failed), `progress` steps, `tool_calls`, `usage` and `error`. |
| Reply message | A normal conversation message with no author. `metadata.blocks` holds the blocks; `metadata.agent_turn_id` links back to the turn. |
| Blocks | Shapes built in code by `BlockBuilder`, never by the model: `markdown`, `table`, `kpi`, `chart`, `callout`, `timeline`, `progress`, `question`, `canvas`. |
| Canvas | A titled document made of the blocks drawn after `open_canvas`. `update_canvas` hands the previous canvas back to the agent so it can edit rather than start over. |

## API

Routes use the configured prefix (default `api`) and middleware (default `['api', 'auth:sanctum']`).

| Method | Path | Action |
|---|---|---|
| POST | `/conversations/{conversation}/agent-turns` | Post `body` as the signed-in user and queue a turn. Optional `harness` from `agent-chat.harnesses`. Returns 202. |
| GET | `/agent-turns` | List turns the user can reach. Filter by `conversation_id` and `status`. |
| GET | `/agent-turns/{turn}` | Status, progress, error and the reply with its blocks. |
| POST | `/agent-turns/{turn}/retry` | Queue a failed turn again. Only the person who asked may retry, since the turn runs with their access (403 for anyone else). 409 for any status but failed. |
| GET | `/agent-turns/{turn}/export?format=md` | Download the turn's canvas. 404 when there is none. |

Post agent prompts through this endpoint rather than the conversations message endpoint. That endpoint accepts an author from the request body, and the agent acts with its author's access.

## Render tools

`render_markdown`, `render_table`, `render_kpi`, `render_chart`, `render_callout`, `render_timeline`, `render_progress`, `ask_question`, `open_canvas`, `update_canvas`. They carry the read permission because drawing changes no data. Each validates its input and returns an error to the agent, drawing nothing, when the input is unusable.

## Events

| Event | When |
|---|---|
| `AgentTurnQueued` | A turn is created or retried. |
| `AgentTurnProgressed` | A new progress step is recorded. |
| `AgentTurnCompleted` | The reply is posted. |
| `AgentTurnFailed` | The turn ends without a reply. |

With `agent-chat.broadcast.enabled`, `AgentTurnUpdated` is also broadcast on `private-agent-chat.conversation.{id}` with `kind` set to `started`, `progress` or `settled`. Authorize that channel in your `routes/channels.php`.

## Customization

Everything below is set in `config/agent-chat.php` (`php artisan vendor:publish --tag=agent-chat-config`):

- `model`, `requests.store_turn`, `resources.turn`, `response_formatter`, `controller`
- `route_actions`, `route_write_middleware`, `register_routes`
- `default_harness` and the `harnesses` a request may pick
- `input_builder`: a `TurnInputBuilder` that decides what text the agent reads. The default folds in the last `history_limit` messages and mentions an earlier canvas.
- `tools` and `register_tools`
- `progress_labels`: step labels keyed by tool name pattern
- `exporters`: one `CanvasExporter` per format
- `queue`: connection, queue, tries, timeout and backoff (seconds between attempts)

## Testing

```bash
make bootstrap
docker compose run --rm app composer test
docker compose run --rm app composer pint:test
```

## License

MIT
