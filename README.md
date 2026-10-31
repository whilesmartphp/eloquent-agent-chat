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
| POST | `/conversations/{conversation}/agent-runs` | Post a prompt as the signed-in user: `body`, `attachments` and/or `answers`, plus optional `harness` (from `agent-chat.harnesses`), `language`, `options` and `hold`. Returns 202. |
| GET | `/agent-runs` | List runs the user can reach. Filter by `conversation_id` and `status`. |
| GET | `/agent-runs/{run}` | Status, progress, the live `draft`, error, the answers the prompt sent, the current attempt and the reply with its blocks and attachments. |
| GET | `/agent-runs/{run}/attempts` | Every attempt in order. |
| GET | `/agent-runs/{run}/events` | A server-sent events stream of the run until it settles. See below. |
| POST | `/agent-runs/{run}/release` | Start a held run. Requester only; 409 unless held. |
| POST | `/agent-runs/{run}/retry` | Queue a failed run again. Requester only, since the run executes with their access; 409 unless failed, and always 409 for a followed run. |
| POST | `/agent-runs/{run}/cancel` | Cancel a run that has not settled. Requester only; 409 once settled. |
| GET | `/agent-runs/{run}/export?format=md` | Download the run's canvas. 404 when there is none. |

Post agent prompts through this endpoint rather than the conversations message endpoint. That endpoint accepts an author from the request body, and the agent acts with its author's access.

## Followed runs

A run can be fed by your own code instead of a harness, so long work outside the agent, such as a video render, shows in the conversation like any answer:

```php
use Whilesmart\AgentChat\Runs\RunFollower;

$follower = app(RunFollower::class);
$run = $follower->start($conversation, $user, 'render', 'Rendering the film', options: ['film_id' => $film->id]);

// later, from the job doing the work, by run or run id:
$follower->progress($run->id)->finish();
$follower->progress($run->id)->start('Mixing the sound');
$follower->text($run->id, 'Scene 3 of 5 is done.');
$follower->block($run->id, ['type' => 'image', 'src' => $still->url]);
$follower->ask($run->id, 'Keep the music?', ['Yes', 'No'], id: 'music');

$follower->complete($run->id, 'The film is ready.', blocks: [...], attachments: [$video]);
// or
$follower->fail($run->id, 'The render farm is down.');
```

| Method | Does |
|---|---|
| `start(Model $conversation, ?Authenticatable $requester, string $name, ?string $label = null, array $options = [], int\|string\|null $promptMessageId = null, ?string $language = null): AgentRun` | Creates a `running` run with harness `follow:<name>`, no attempt and no harness execution, and starts `$label` as its first step. Throws `AuthorizationException` when the requester cannot reach the conversation's owner. |
| `progress($run): RunProgress` | Steps saved on the run: `start()`, `finish()`, `fail()`. |
| `text($run, string $text)` | Replaces the draft's text. |
| `block($run, array $block): array` | Adds a block to the draft, checked like any block a tool adds. |
| `ask($run, string $prompt, array $options, ?string $id = null, bool $multiple = false, bool\|string $writeIn = false, ?string $why = null, ?string $recommended = null): array` | Adds a question block, with the same settings as `ask_question`. |
| `complete($run, string $text = '', array $blocks = [], array $attachments = []): bool` | Publishes the reply (the text, the draft's blocks, then `$blocks`, with the attachments) the way an agent run does, in the transaction that completes the run. Running steps are marked done. False when the run had already settled. |
| `fail($run, string $reason)` | Fails the run; running steps are marked failed. |

The run fires `AgentRunStarted`, `AgentRunProgressed`, `AgentRunCompleted` and `AgentRunFailed`, broadcasts like any run, and appears on the events stream. Access follows the conversation's owner. The requester can cancel it; after that every call does nothing, so the job can check the run's `status` and stop. Retrying a followed run is refused with 409, since there is no harness to run again; start a new one instead. A followed run never holds up prompts under the `queue` or `reject` concurrency policies. The draft is written by reading and saving the run, so feed one run from one job at a time.

## Live draft

While a run works, the reply it is writing is kept on the run as `draft`:

```json
{ "attempt_id": 3, "text": "Here is the week so far", "blocks": [{ "type": "kpi", "items": [] }] }
```

`text` is the text the model has streamed so far and `blocks` are the blocks drawn so far, in order. The draft is saved at most once per `streaming.draft_throttle_ms` (250 by default), and right away when a tool starts or finishes. A new attempt starts a new draft. The draft is cleared when the reply is published or the run fails or is cancelled. The reply message is still created once, when the run completes, so channels such as SMS and email only ever get the finished answer.

## Events stream

`GET /agent-runs/{run}/events` answers with `text/event-stream` for anyone who can see the run. It polls the stored run every `streaming.poll_ms` (500 by default) and sends only what changed. Each event's `data` is JSON:

| Event | Data |
|---|---|
| `started` | `{run_id, status, harness, started_at}`, once the run has started. |
| `progress` | `{steps}`: every step, sent whenever they change. |
| `text` | `{text}`: the whole text so far, not a delta, so a client always shows the latest one. |
| `block` | `{index, block}`: one event per new block in the draft. |
| `reset` | `{attempt_id}`: a new attempt started over; drop the text and blocks shown so far. |
| `settled` | `{status, reply_message_id, error}`: the run is completed, failed or cancelled. The stream ends. Fetch the run for the reply. |
| `timeout` | `{status}`: `streaming.max_seconds` (120 by default) passed first. The stream ends; connect again to keep following. |

Each open stream holds a PHP worker while it polls, so size your web workers for it, or follow runs by broadcasting instead.

## Render tools

`render_markdown`, `render_table`, `render_kpi`, `render_chart`, `render_callout`, `render_timeline`, `render_progress`, `ask_question`, `open_canvas`, `update_canvas`. They carry the read permission because drawing changes no data. Each validates its input and returns an error to the agent, drawing nothing, when the input is unusable.

## Questions

`ask_question` draws a question block. Each question and option has an id, so the answer can be sent back and shown with the question later:

```json
{
  "type": "question",
  "id": "week",
  "prompt": "Which week?",
  "options": [
    { "id": "this-week", "label": "This week", "message": "This week" },
    { "id": "last-week", "label": "Last week", "message": "Last week" }
  ],
  "multiple": false,
  "write_in": "Another week",
  "why": "The report covers one week.",
  "recommended": "this-week"
}
```

- `id` is generated (`q_` and a ULID) when the agent gives none. Option ids come from their labels unless given.
- `multiple` allows several options. `write_in` is `false`, `true`, or the label of a box for an answer in the person's own words.
- `why` and `recommended` (an option id) are left out when not set.
- `label` and `message` stay on each option, so a client that sends the option's `message` as the next prompt keeps working.

A tool of yours can file a question it got from elsewhere with `BlockCollector::add(['type' => 'question', ...])` in the same shape; it is given ids the same way.

To answer, send `answers` with the next prompt, keyed by question id. Each value is an option id, a list of option ids (for a `multiple` question), or text (when the question has `write_in`). `body` may then be left out:

```json
{ "body": "Here you go", "answers": { "week": "last-week", "projects": ["shop", "harbor"] } }
```

Answers are checked against the questions in the conversation's latest reply that asked any. An unknown question id or an answer that does not fit is refused with 422. They are stored on the prompt message's `metadata.answers`, beside `metadata.answers_to_message_id` (the reply that asked), and shown on the run as `answers`. The agent reads them after the prompt, each with the question it settles.

Stored reply blocks never change. When a run is read, each question block in its reply gets `answer` (`{"options": ["last-week"], "text": null}`, or null) and `answered_by_message_id` (the prompt message that answered it first, or null).

## Your own blocks

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
| `AgentRunStarted` | A worker starts executing a run, or a followed run starts. |
| `AgentRunProgressed` | A new progress step starts. |
| `AgentRunAttemptFailed` | An attempt fails; the run may still try again. |
| `AgentRunCompleted` | The reply is posted. |
| `AgentRunFailed` | The run ends without a reply. |
| `AgentRunCancelled` | The requester cancels the run. |

With `agent-chat.broadcast.enabled`, `AgentRunUpdated` is also broadcast on `private-agent-chat.conversation.{id}` with `kind` set to `started`, `progress`, `text`, `block` or `settled`. `text` and `block` are sent when the draft is saved, so they follow the same throttle; fetch the run for the draft itself. Authorize that channel in your `routes/channels.php`.

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
- `streaming`: `draft_throttle_ms`, and `poll_ms` and `max_seconds` for the events stream
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
